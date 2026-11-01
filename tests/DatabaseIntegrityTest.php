<?php
declare(strict_types=1);
namespace Tests;

use App\Controllers\AdminAuthController;
use App\Controllers\AdminCatalogController;
use App\Controllers\AdminImportController;
use App\Controllers\AdminReviewController;
use App\Controllers\CatalogController;
use App\Controllers\PriceHistoryController;
use App\Controllers\ReviewsController;
use App\Controllers\SubscriptionController;
use App\Core\Database;
use App\Security\AdminGuard;
use App\Services\OAuthService;
use App\Services\PushNotificationService;
use App\Services\TokenService;
use App\Core\HttpException;
use App\Core\Request;
use PDO;
use PHPUnit\Framework\TestCase;

final class DatabaseIntegrityTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        if (getenv('RUN_DB_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_DB_TESTS=1 to run MySQL integration tests.');
        }
        $this->pdo = Database::connection();
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
        if ($this->pdo && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function testMigrationsCreateAllControllerTablesAndColumns(): void
    {
        $expected = [
            'branches' => ['id','name','slug','address','phone','sort_order','is_active'],
            'categories' => ['id','parent_id','name','slug','image_url','sort_order','is_active'],
            'products' => ['id','category_id','catalog_source_branch_id','name','slug','sku','barcode','description','image_url','unit','is_active'],
            'stock' => ['id','branch_id','product_id','price','quantity','available_since','last_import_id','updated_at'],
            'price_history' => ['id','branch_id','product_id','price','recorded_at','source'],
            'users' => ['id','device_uuid','display_name','email'],
            'api_tokens' => ['id','user_id','token_hash','expires_at','last_used_at'],
            'social_accounts' => ['id','user_id','provider','provider_user_id'],
            'subscriptions' => ['id','user_id','product_id','branch_id','status','notified_at','updated_at'],
            'reviews' => ['id','user_id','product_id','rating','body','status','moderated_at','moderated_by','updated_at'],
            'admins' => ['id','login','password_hash','role','is_active','last_login_at'],
            'import_log' => ['id','branch_id','original_filename','file_sha256','status','rows_total','rows_created','rows_updated','rows_unchanged','rows_errors','started_at','finished_at'],
            'import_errors' => ['id','import_id','row_number','error_code','message','row_data'],
            'push_delivery_log' => ['id','subscription_id','event_key','status','attempt_count','locked_at','attempted_at','last_error','response','sent_at'],
            'admin_audit_log' => ['id','admin_id','action','entity_type','entity_id','ip_address','details'],
            'admin_login_attempts' => ['id','login','ip_address','attempted_at'],
            'oauth_attempts' => ['id','provider','state_hash','code_verifier','redirect_uri','device_id','link_user_id','expires_at','consumed_at'],
            'oauth_handoff_codes' => ['id','user_id','code_hash','expires_at','consumed_at'],
            'api_rate_limits' => ['bucket_hash','attempts','window_started_at','updated_at'],
            'schema_migrations' => ['migration','applied_at'],
        ];

        foreach ($expected as $table => $columns) {
            $stmt = $this->pdo->query('SHOW COLUMNS FROM `' . $table . '`');
            $actual = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
            foreach ($columns as $column) {
                self::assertContains($column, $actual, "Missing {$table}.{$column}");
            }
        }

        self::assertSame(9, (int)$this->pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());

        $fk = $this->pdo->prepare(
            "SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock' AND COLUMN_NAME='last_import_id'
               AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1"
        );
        $fk->execute();
        self::assertSame('import_log', $fk->fetchColumn(), 'stock.last_import_id must reference import_log.id');
    }


    public function testMySqlEnforcesStockPriceAndImportRelations(): void
    {
        [$branchOne, $branchTwo, $productId] = $this->seedCatalog();

        $this->pdo->prepare(
            'INSERT INTO stock (branch_id,product_id,price,quantity) VALUES (:branch_id,:product_id,10.00,1.000)'
        )->execute(['branch_id' => $branchOne, 'product_id' => $productId]);

        $violations = [
            'duplicate branch/product stock row' => function () use ($branchOne, $productId): void {
                $this->pdo->prepare(
                    'INSERT INTO stock (branch_id,product_id,price,quantity) VALUES (:branch_id,:product_id,11.00,2.000)'
                )->execute(['branch_id' => $branchOne, 'product_id' => $productId]);
            },
            'negative stock quantity' => function () use ($branchTwo, $productId): void {
                $this->pdo->prepare(
                    'INSERT INTO stock (branch_id,product_id,price,quantity) VALUES (:branch_id,:product_id,10.00,-1.000)'
                )->execute(['branch_id' => $branchTwo, 'product_id' => $productId]);
            },
            'negative stock price' => function () use ($branchTwo, $productId): void {
                $this->pdo->prepare(
                    'INSERT INTO stock (branch_id,product_id,price,quantity) VALUES (:branch_id,:product_id,-1.00,0.000)'
                )->execute(['branch_id' => $branchTwo, 'product_id' => $productId]);
            },
            'negative price history value' => function () use ($branchOne, $productId): void {
                $this->pdo->prepare(
                    "INSERT INTO price_history (branch_id,product_id,price,recorded_at,source)
                     VALUES (:branch_id,:product_id,-1.00,NOW(),'admin')"
                )->execute(['branch_id' => $branchOne, 'product_id' => $productId]);
            },
            'unknown last_import_id' => function () use ($branchTwo, $productId): void {
                $this->pdo->prepare(
                    'INSERT INTO stock (branch_id,product_id,price,quantity,last_import_id)
                     VALUES (:branch_id,:product_id,10.00,0.000,999999999)'
                )->execute(['branch_id' => $branchTwo, 'product_id' => $productId]);
            },
        ];

        foreach ($violations as $description => $operation) {
            try {
                $operation();
                self::fail('MySQL did not reject ' . $description . '.');
            } catch (\PDOException $e) {
                self::assertNotSame('', $e->getMessage(), 'MySQL must explain why it rejected ' . $description);
            }
        }
    }

    public function testCatalogAndPriceHistoryQueriesReturnBranchSpecificValues(): void
    {
        [$branchOne, $branchTwo, $productId] = $this->seedCatalog();
        foreach ([[$branchOne, '10.50', '3.000'], [$branchTwo, '12.75', '0.000']] as [$branchId, $price, $quantity]) {
            $this->pdo->prepare(
                'INSERT INTO stock (branch_id,product_id,price,quantity) VALUES (:branch_id,:product_id,:price,:quantity)'
            )->execute(['branch_id' => $branchId, 'product_id' => $productId, 'price' => $price, 'quantity' => $quantity]);
            $this->pdo->prepare(
                "INSERT INTO price_history (branch_id,product_id,price,recorded_at,source)
                 VALUES (:branch_id,:product_id,:price,NOW(),'import')"
            )->execute(['branch_id' => $branchId, 'product_id' => $productId, 'price' => $price]);
        }

        $payload = $this->capture(fn() => (new CatalogController())->products(
            new Request('GET', '/api/v1/products', ['branch_id' => (string)$branchOne], [], [])
        ));
        self::assertTrue($payload['success']);
        self::assertSame('10.50', (string)$payload['data'][0]['price']);
        self::assertSame('3.000', (string)$payload['data'][0]['quantity']);
        self::assertSame(1, (int)$payload['data'][0]['is_available']);

        // A product may be in the global catalog before a branch has imported it.
        // The API must represent this as zero stock / unavailable, not SQL NULL.
        $suffix = bin2hex(random_bytes(6));
        $this->pdo->prepare(
            'INSERT INTO products (name,slug,is_active) VALUES (:name,:slug,1)'
        )->execute(['name' => 'No stock row product', 'slug' => 'no-stock-' . $suffix]);
        $withoutStockId = (int)$this->pdo->lastInsertId();
        $branchCatalog = $this->capture(fn() => (new CatalogController())->products(
            new Request('GET', '/api/v1/products', ['branch_id' => (string)$branchOne], [], [])
        ));
        $withoutStock = array_values(array_filter(
            $branchCatalog['data'],
            static fn(array $item): bool => (int)$item['id'] === $withoutStockId
        ));
        self::assertCount(1, $withoutStock);
        self::assertSame('0.000', (string)$withoutStock[0]['quantity']);
        self::assertSame(0, (int)$withoutStock[0]['is_available']);
        self::assertNull($withoutStock[0]['price']);

        $detail = $this->capture(fn() => (new CatalogController())->productDetail(
            new Request('GET', '/api/v1/products/detail', ['id' => (string)$withoutStockId], [], [])
        ));
        $branchWithoutStock = array_values(array_filter(
            $detail['data']['branches'],
            static fn(array $item): bool => (int)$item['branch_id'] === $branchOne
        ));
        self::assertCount(1, $branchWithoutStock);
        self::assertSame('0.000', (string)$branchWithoutStock[0]['quantity']);
        self::assertSame(0, (int)$branchWithoutStock[0]['is_available']);

        // Products assigned to an inactive category must not leak into the public catalog.
        $inactiveCategorySuffix = bin2hex(random_bytes(6));
        $this->pdo->prepare(
            'INSERT INTO categories (name,slug,is_active) VALUES (:name,:slug,0)'
        )->execute(['name' => 'Inactive category', 'slug' => 'inactive-' . $inactiveCategorySuffix]);
        $inactiveCategoryId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO products (category_id,name,slug,is_active) VALUES (:category_id,:name,:slug,1)'
        )->execute([
            'category_id' => $inactiveCategoryId,
            'name' => 'Hidden category product',
            'slug' => 'hidden-category-product-' . $inactiveCategorySuffix,
        ]);
        $hiddenProductId = (int)$this->pdo->lastInsertId();
        $publicCatalog = $this->capture(fn() => (new CatalogController())->products(
            new Request('GET', '/api/v1/products', [], [], [])
        ));
        self::assertNotContains($hiddenProductId, array_map(
            static fn(array $item): int => (int)$item['id'],
            $publicCatalog['data']
        ));
        $hiddenDetail = $this->capture(fn() => (new CatalogController())->productDetail(new Request(
            'GET', '/api/v1/products/detail', ['id' => (string)$hiddenProductId], [], []
        )));
        self::assertFalse($hiddenDetail['success']);
        self::assertSame('PRODUCT_NOT_FOUND', $hiddenDetail['error']['code']);

        $history = $this->capture(fn() => (new PriceHistoryController())->index(
            new Request('GET', '/api/v1/products/price-history', [
                'product_id' => (string)$productId, 'branch_id' => (string)$branchTwo,
            ], [], [])
        ));
        self::assertSame('12.75', (string)$history['data']['history'][0]['price']);
        self::assertSame($branchTwo, (int)$history['data']['branch_id']);

        // Inactive products and branches must not expose a public price history.
        $this->pdo->prepare('UPDATE products SET is_active=0 WHERE id=:id')->execute(['id' => $productId]);
        try {
            (new PriceHistoryController())->index(new Request(
                'GET', '/api/v1/products/price-history',
                ['product_id' => (string)$productId, 'branch_id' => (string)$branchTwo], [], []
            ));
            self::fail('Inactive products must not expose public price history.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }

        $this->pdo->prepare('UPDATE products SET is_active=1 WHERE id=:id')->execute(['id' => $productId]);
        $this->pdo->prepare('UPDATE branches SET is_active=0 WHERE id=:id')->execute(['id' => $branchTwo]);
        try {
            (new PriceHistoryController())->index(new Request(
                'GET', '/api/v1/products/price-history',
                ['product_id' => (string)$productId, 'branch_id' => (string)$branchTwo], [], []
            ));
            self::fail('Inactive branches must not expose public price history.');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
    }

    public function testSubscriptionAndReviewQueriesRespectUserAndProductRelations(): void
    {
        [$branchOne, , $productId] = $this->seedCatalog();
        $this->pdo->prepare(
            'INSERT INTO stock (branch_id,product_id,price,quantity) VALUES (:branch_id,:product_id,10.00,5.000)'
        )->execute(['branch_id' => $branchOne, 'product_id' => $productId]);
        $this->pdo->prepare('INSERT INTO users (display_name) VALUES (:name)')
            ->execute(['name' => 'Integration test']);
        $userId = (int)$this->pdo->lastInsertId();
        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare(
            'INSERT INTO api_tokens (user_id,token_hash,expires_at) VALUES (:user_id,:hash,DATE_ADD(NOW(),INTERVAL 1 DAY))'
        )->execute(['user_id' => $userId, 'hash' => hash('sha256', $token)]);
        $requestHeaders = ['Authorization' => 'Bearer ' . $token];
        // SubscriptionController owns its transaction, so commit fixture data first.
        $this->pdo->commit();

        try {
            (new SubscriptionController())->create(
                new Request('POST', '/api/v1/subscriptions', [], $requestHeaders, [
                    'product_id' => $productId, 'branch_id' => $branchOne,
                ])
            );
            self::fail('A subscription must be rejected while stock is positive.');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
        $this->pdo->prepare(
            'UPDATE stock SET quantity=0 WHERE branch_id=:branch_id AND product_id=:product_id'
        )->execute(['branch_id' => $branchOne, 'product_id' => $productId]);

        $created = $this->capture(fn() => (new SubscriptionController())->create(
            new Request('POST', '/api/v1/subscriptions', [], $requestHeaders, [
                'product_id' => $productId, 'branch_id' => $branchOne,
            ])
        ));
        $subscriptionId = (int)$created['data']['subscription']['id'];
        self::assertGreaterThan(0, $subscriptionId);

        $listed = $this->capture(fn() => (new SubscriptionController())->index(
            new Request('GET', '/api/v1/subscriptions', [], $requestHeaders, [])
        ));
        self::assertCount(1, $listed['data']['subscriptions']);
        self::assertSame($branchOne, (int)$listed['data']['subscriptions'][0]['branch_id']);

        $this->pdo->prepare(
            "INSERT INTO social_accounts (user_id,provider,provider_user_id) VALUES (:user_id,'vk',:provider_user_id)"
        )->execute(['user_id' => $userId, 'provider_user_id' => 'test-' . bin2hex(random_bytes(8))]);
        $saved = $this->capture(fn() => (new ReviewsController())->save(
            new Request('POST', '/api/v1/products/reviews', [], $requestHeaders, [
                'product_id' => $productId, 'rating' => 5, 'body' => 'Проверка связи с товаром',
            ])
        ));
        self::assertSame('pending', $saved['data']['review']['status']);

        $this->pdo->prepare("UPDATE reviews SET status='published' WHERE id=:id")
            ->execute(['id' => $saved['data']['review']['id']]);
        $reviews = $this->capture(fn() => (new ReviewsController())->index(
            new Request('GET', '/api/v1/products/reviews', ['product_id' => (string)$productId], [], [])
        ));
        self::assertSame(1, (int)$reviews['data']['summary']['reviews_count']);
        self::assertSame(5.0, (float)$reviews['data']['summary']['average_rating']);

        $cancelled = $this->capture(fn() => (new SubscriptionController())->cancel(
            new Request('POST', '/api/v1/subscriptions/cancel', [], $requestHeaders, [
                'subscription_id' => $subscriptionId,
            ])
        ));
        self::assertSame('cancelled', $cancelled['data']['status']);
    }


    public function testAdminCatalogImportAndReviewQueriesUseLiveSchemaAndRoles(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare(
            'INSERT INTO admins (login,password_hash,role,is_active) VALUES (:login,:hash,\'owner\',1)'
        )->execute(['login' => 'audit-' . $suffix, 'hash' => password_hash('test-password', PASSWORD_DEFAULT)]);
        $adminId = (int)$this->pdo->lastInsertId();
        // Admin controllers own their transactions; this scenario runs without
        // the per-test wrapping transaction used by the read-only API tests.
        $this->pdo->commit();

        AdminGuard::startSession();
        $_SESSION = [
            'admin' => ['id' => $adminId, 'login' => 'audit-' . $suffix, 'role' => 'owner'],
            'csrf_token' => 'csrf-' . $suffix,
            'admin_last_activity' => time(),
        ];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'csrf-' . $suffix;

        $adminCatalog = new AdminCatalogController();
        $category = $this->capture(fn() => $adminCatalog->saveCategory(
            new Request('POST', '/api/v1/admin/categories/save', [], [], [
                'name' => 'Audit category', 'slug' => 'audit-category-' . $suffix,
                'parent_id' => '', 'image_url' => '', 'sort_order' => 0, 'is_active' => true,
            ])
        ));
        $categoryId = (int)$category['data']['id'];

        $branch = $this->capture(fn() => $adminCatalog->saveBranch(
            new Request('POST', '/api/v1/admin/branches/save', [], [], [
                'name' => 'Audit branch', 'slug' => 'audit-branch-' . $suffix,
                'address' => 'Test address', 'phone' => '', 'sort_order' => 0, 'is_active' => true,
            ])
        ));
        $branchId = (int)$branch['data']['id'];

        $product = $this->capture(fn() => $adminCatalog->saveProduct(
            new Request('POST', '/api/v1/admin/products/save', [], [], [
                'name' => 'Audit product', 'slug' => 'audit-product-' . $suffix,
                'sku' => 'AUD-' . $suffix, 'barcode' => '', 'unit' => 'шт.',
                'description' => 'Integration test', 'image_url' => '',
                'category_id' => $categoryId, 'is_active' => true,
            ])
        ));
        $productId = (int)$product['data']['id'];

        $stockSaved = $this->capture(fn() => $adminCatalog->saveStock(
            new Request('POST', '/api/v1/admin/stock/save', [], [], [
                'branch_id' => $branchId, 'product_id' => $productId,
                'price' => '19,90', 'quantity' => '2',
            ])
        ));
        self::assertTrue($stockSaved['data']['restocked']);

        self::assertCount(1, $this->capture(fn() => $adminCatalog->categories(
            new Request('GET', '/api/v1/admin/categories', [], [], [])
        ))['data']['items']);
        self::assertGreaterThanOrEqual(1, count($this->capture(fn() => $adminCatalog->branches(
            new Request('GET', '/api/v1/admin/branches', [], [], [])
        ))['data']['items']));
        self::assertGreaterThanOrEqual(1, count($this->capture(fn() => $adminCatalog->products(
            new Request('GET', '/api/v1/admin/products', [], [], [])
        ))['data']['items']));
        self::assertCount(1, $this->capture(fn() => $adminCatalog->stock(
            new Request('GET', '/api/v1/admin/stock', ['branch_id' => (string)$branchId], [], [])
        ))['data']['items']);

        $fileHash = hash('sha256', 'audit-' . $suffix);
        $this->pdo->prepare(
            "INSERT INTO import_log (branch_id,original_filename,file_sha256,status,started_at,finished_at)
             VALUES (:branch_id,'audit.xlsx',:hash,'completed',NOW(),NOW())"
        )->execute(['branch_id' => $branchId, 'hash' => $fileHash]);
        $importId = (int)$this->pdo->lastInsertId();
        $rowNumberColumn = chr(96) . 'row_number' . chr(96);
        $this->pdo->prepare(
            'INSERT INTO import_errors (import_id,' . $rowNumberColumn . ',error_code,message,row_data)
             VALUES (:import_id,12,\'ROW_DATA_INVALID\',\'Bad test row\',JSON_OBJECT(\'sku\',\'x\'))'
        )->execute(['import_id' => $importId]);

        $adminImports = new AdminImportController();
        self::assertCount(1, $this->capture(fn() => $adminImports->index(
            new Request('GET', '/api/v1/admin/imports', ['branch_id' => (string)$branchId], [], [])
        ))['data']['items']);
        $errors = $this->capture(fn() => $adminImports->errors(
            new Request('GET', '/api/v1/admin/import-errors', ['import_id' => (string)$importId], [], [])
        ));
        self::assertSame(12, (int)$errors['data']['errors'][0]['row_number']);

        $this->pdo->prepare('INSERT INTO users (display_name) VALUES (:name)')
            ->execute(['name' => 'Audit reviewer']);
        $userId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO reviews (user_id,product_id,rating,body,status)
             VALUES (:user_id,:product_id,4,'Moderation test','pending')"
        )->execute(['user_id' => $userId, 'product_id' => $productId]);
        $reviewId = (int)$this->pdo->lastInsertId();

        $adminReviews = new AdminReviewController();
        $moderated = $this->capture(fn() => $adminReviews->moderate(
            new Request('POST', '/api/v1/admin/reviews/moderate', [], [], [
                'review_id' => $reviewId, 'status' => 'published',
            ])
        ));
        self::assertSame('published', $moderated['data']['status']);
        self::assertGreaterThanOrEqual(1, count($this->capture(fn() => $adminReviews->index(
            new Request('GET', '/api/v1/admin/reviews', ['status' => 'published'], [], [])
        ))['data']['items']));

        $this->pdo->prepare("UPDATE admins SET role='moderator' WHERE id=:id")->execute(['id' => $adminId]);
        try {
            AdminGuard::requireRole(['owner']);
            self::fail('A changed moderator role must not retain owner access.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        $this->pdo->prepare('UPDATE admins SET is_active=0 WHERE id=:id')->execute(['id' => $adminId]);
        try {
            AdminGuard::requireAdmin();
            self::fail('A disabled administrator must lose access immediately.');
        } catch (HttpException $e) {
            self::assertSame(401, $e->status);
        }
    }


    public function testAdminLoginMeAndLogoutUseCurrentDatabaseAccount(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $login = 'login-' . $suffix;
        $password = 'Strong-test-password-2026';
        $this->pdo->prepare(
            'INSERT INTO admins (login,password_hash,role,is_active) VALUES (:login,:hash,\'owner\',1)'
        )->execute(['login' => $login, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
        $adminId = (int)$this->pdo->lastInsertId();

        AdminGuard::startSession();
        $_SESSION = ['csrf_token' => 'prelogin-' . $suffix];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'prelogin-' . $suffix;
        $oldSessionId = session_id();

        $auth = new AdminAuthController();
        $loggedIn = $this->capture(fn() => $auth->login(
            new Request('POST', '/api/v1/admin/login', [], [], [
                'login' => $login, 'password' => $password,
            ])
        ));
        self::assertSame($adminId, (int)$loggedIn['data']['admin']['id']);
        self::assertNotSame($oldSessionId, session_id());
        self::assertNotSame('', $loggedIn['data']['csrf_token']);

        $_SERVER['HTTP_X_CSRF_TOKEN'] = $loggedIn['data']['csrf_token'];
        $me = $this->capture(fn() => $auth->me(
            new Request('GET', '/api/v1/admin/me', [], [], [])
        ));
        self::assertSame($login, $me['data']['admin']['login']);

        $this->capture(fn() => $auth->logout(
            new Request('POST', '/api/v1/admin/logout', [], [], [])
        ));
        self::assertSame(PHP_SESSION_NONE, session_status());

        $check = $this->pdo->prepare('SELECT last_login_at FROM admins WHERE id=:id');
        $check->execute(['id' => $adminId]);
        self::assertNotFalse($check->fetchColumn());
    }


    public function testOAuthSocialAccountLinkingAndHandoffCodesAreTransactionalAndOneTime(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare('INSERT INTO users (display_name) VALUES (:name)')
            ->execute(['name' => 'OAuth guest']);
        $guestId = (int)$this->pdo->lastInsertId();
        $this->pdo->commit();

        $service = new OAuthService();
        $linkMethod = new \ReflectionMethod($service, 'createOrLinkAccount');
        $linkMethod->setAccessible(true);

        $code = $linkMethod->invoke(
            $service, 'yandex', 'yandex-' . $suffix, 'OAuth User', 'oauth-' . $suffix . '@example.test', null
        );
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $code);
        $issued = $service->exchangeHandoffCode($code);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $issued['token']);

        $account = $this->pdo->prepare(
            "SELECT sa.user_id,u.display_name,u.email
             FROM social_accounts sa JOIN users u ON u.id=sa.user_id
             WHERE sa.provider='yandex' AND sa.provider_user_id=:provider_user_id"
        );
        $account->execute(['provider_user_id' => 'yandex-' . $suffix]);
        $row = $account->fetch();
        self::assertNotFalse($row);
        self::assertSame('OAuth User', $row['display_name']);
        self::assertSame('oauth-' . $suffix . '@example.test', $row['email']);

        $tokenCheck = $this->pdo->prepare('SELECT COUNT(*) FROM api_tokens WHERE user_id=:user_id AND token_hash=:hash');
        $tokenCheck->execute(['user_id' => $row['user_id'], 'hash' => hash('sha256', $issued['token'])]);
        self::assertSame(1, (int)$tokenCheck->fetchColumn());

        try {
            $service->exchangeHandoffCode($code);
            self::fail('A handoff code must not be reusable.');
        } catch (HttpException $e) {
            self::assertSame(401, $e->status);
        }

        $linkedCode = $linkMethod->invoke(
            $service, 'vk', 'vk-' . $suffix, 'Linked guest', null, $guestId
        );
        $linked = $this->pdo->prepare(
            "SELECT user_id FROM social_accounts WHERE provider='vk' AND provider_user_id=:provider_user_id"
        );
        $linked->execute(['provider_user_id' => 'vk-' . $suffix]);
        self::assertSame($guestId, (int)$linked->fetchColumn());

        $this->pdo->prepare('INSERT INTO users (display_name) VALUES (:name)')
            ->execute(['name' => 'Other guest']);
        $otherGuestId = (int)$this->pdo->lastInsertId();
        try {
            $linkMethod->invoke($service, 'vk', 'vk-' . $suffix, 'Other', null, $otherGuestId);
            self::fail('A social account already linked to another user must be rejected.');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }

        $linkedIssued = $service->exchangeHandoffCode($linkedCode);
        self::assertSame($guestId, (int)$linkedIssued['user_id']);
    }


    public function testExcelImportResolvesBarcodeAndSkuWithoutMergingDifferentProducts(): void
    {
        [$branchId] = $this->seedCatalog();
        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare(
            'INSERT INTO import_log (branch_id,original_filename,file_sha256,status,started_at)
             VALUES (:branch_id,\'test.xlsx\',:hash,\'processing\',NOW())'
        )->execute(['branch_id' => $branchId, 'hash' => hash('sha256', 'import-' . $suffix)]);
        $importId = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO products (name,slug,sku,barcode,unit,catalog_source_branch_id)
             VALUES (\'Old milk\',:slug,:sku,NULL,\'шт.\',:branch_id)'
        )->execute(['slug' => 'milk-' . $suffix, 'sku' => 'MILK-' . $suffix, 'branch_id' => $branchId]);
        $productId = (int)$this->pdo->lastInsertId();

        $service = new \App\Services\ExcelImportService();
        $method = new \ReflectionMethod($service, 'upsertProductAndStock');
        $method->setAccessible(true);
        $record = [
            'name' => 'Молоко 1 л',
            'barcode' => '0001234567890',
            'sku' => 'MILK-' . $suffix,
            'price' => '89.90',
            'quantity' => '4.000',
            'unit' => 'шт.',
        ];
        $result = $method->invoke($service, $this->pdo, $record, $branchId, $importId);
        self::assertSame($productId, (int)$result['product_id']);
        self::assertFalse($result['created']);
        self::assertTrue($result['changed']);
        self::assertTrue($result['restocked']);

        $check = $this->pdo->prepare('SELECT barcode,name FROM products WHERE id=:id');
        $check->execute(['id' => $productId]);
        $product = $check->fetch();
        self::assertSame('0001234567890', $product['barcode']);
        self::assertSame('Молоко 1 л', $product['name']);

        $stock = $this->pdo->prepare('SELECT price,quantity,last_import_id FROM stock WHERE branch_id=:branch_id AND product_id=:product_id');
        $stock->execute(['branch_id' => $branchId, 'product_id' => $productId]);
        $stockRow = $stock->fetch();
        self::assertSame('89.90', $stockRow['price']);
        self::assertSame('4.000', $stockRow['quantity']);
        self::assertSame($importId, (int)$stockRow['last_import_id']);

        $history = $this->pdo->prepare('SELECT COUNT(*) FROM price_history WHERE branch_id=:branch_id AND product_id=:product_id');
        $history->execute(['branch_id' => $branchId, 'product_id' => $productId]);
        self::assertSame(1, (int)$history->fetchColumn());

        $conflictProduct = $this->pdo->prepare(
            'INSERT INTO products (name,slug,sku,barcode,unit) VALUES (\'Other product\',:slug,:sku,\'2222222222222\',\'шт.\')'
        );
        $conflictProduct->execute(['slug' => 'other-' . $suffix, 'sku' => 'OTHER-' . $suffix]);
        $conflictingRecord = $record;
        $conflictingRecord['sku'] = 'OTHER-' . $suffix;
        $this->expectException(\RuntimeException::class);
        $method->invoke($service, $this->pdo, $conflictingRecord, $branchId, $importId);
    }


    public function testPushDeliveryClaimsAreIdempotentAndRetryQueriesMatchSchema(): void
    {
        [$branchId, , $productId] = $this->seedCatalog();
        $this->pdo->prepare('INSERT INTO users (display_name) VALUES (\'Push user one\')')->execute();
        $userOne = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO users (display_name) VALUES (\'Push user two\')')->execute();
        $userTwo = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO subscriptions (user_id,product_id,branch_id,status)
             VALUES (:user_id,:product_id,:branch_id,'active')"
        )->execute(['user_id' => $userOne, 'product_id' => $productId, 'branch_id' => $branchId]);
        $successSubscriptionId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            "INSERT INTO subscriptions (user_id,product_id,branch_id,status)
             VALUES (:user_id,:product_id,:branch_id,'active')"
        )->execute(['user_id' => $userTwo, 'product_id' => $productId, 'branch_id' => $branchId]);
        $retrySubscriptionId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO stock (branch_id,product_id,price,quantity) VALUES (:branch_id,:product_id,10.00,2.000)'
        )->execute(['branch_id' => $branchId, 'product_id' => $productId]);
        $this->pdo->commit();

        $service = new PushNotificationService();
        $claim = new \ReflectionMethod($service, 'claimDelivery');
        $claim->setAccessible(true);
        $finish = new \ReflectionMethod($service, 'finishDelivery');
        $finish->setAccessible(true);

        $successEventKey = 'restock-success-' . bin2hex(random_bytes(4));
        $deliveryId = $claim->invoke($service, $this->pdo, $successSubscriptionId, $successEventKey);
        self::assertGreaterThan(0, $deliveryId);
        self::assertNull($claim->invoke($service, $this->pdo, $successSubscriptionId, $successEventKey));

        $finish->invoke($service, $this->pdo, $deliveryId, true, 'onesignal-test-id', ['id' => 'onesignal-test-id'], null);
        $status = $this->pdo->prepare('SELECT status FROM subscriptions WHERE id=:id');
        $status->execute(['id' => $successSubscriptionId]);
        self::assertSame('triggered', $status->fetchColumn());

        // Simulate a process crash after the stock transaction commits:
        // the pending outbox row must still be picked up by the retry worker.
        $retryEventKey = 'restock-retry-' . bin2hex(random_bytes(4));
        $this->pdo->beginTransaction();
        self::assertSame(1, $service->enqueueRestock($this->pdo, $productId, $branchId, $retryEventKey));
        self::assertSame(0, $service->enqueueRestock($this->pdo, $productId, $branchId, $retryEventKey));
        $this->pdo->commit();

        $result = $service->retryFailedDeliveries(10);
        self::assertSame(1, $result['matched']);
        self::assertSame(1, $result['failed']);

        $delivery = $this->pdo->prepare(
            'SELECT id,status,attempt_count,last_error FROM push_delivery_log WHERE subscription_id=:id AND event_key=:event_key'
        );
        $delivery->execute(['id' => $retrySubscriptionId, 'event_key' => $retryEventKey]);
        $failed = $delivery->fetch();
        self::assertSame('failed', $failed['status']);
        self::assertSame(1, (int)$failed['attempt_count']);

        $this->pdo->prepare(
            'UPDATE push_delivery_log SET attempted_at=DATE_SUB(NOW(),INTERVAL 20 MINUTE) WHERE id=:id'
        )->execute(['id' => $failed['id']]);
        $retry = $service->retryFailedDeliveries(10);
        self::assertSame(1, $retry['matched']);
        self::assertSame(1, $retry['failed']);

        $this->pdo->prepare(
            "UPDATE push_delivery_log SET last_error='NO_SUBSCRIBED_DEVICES',
             attempted_at=DATE_SUB(NOW(),INTERVAL 20 MINUTE) WHERE id=:id"
        )->execute(['id' => $failed['id']]);
        $noDeviceRetry = $service->retryFailedDeliveries(10);
        self::assertSame(0, $noDeviceRetry['matched']);

        // A branch can be deactivated after an outbox event is committed.
        // Do not send a notification for a branch no longer visible to customers.
        $inactiveBranchEventKey = 'restock-inactive-branch-' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            "INSERT INTO push_delivery_log (subscription_id,event_key,status)
             VALUES (:subscription_id,:event_key,'pending')"
        )->execute(['subscription_id' => $retrySubscriptionId, 'event_key' => $inactiveBranchEventKey]);
        $this->pdo->prepare('UPDATE branches SET is_active=0 WHERE id=:id')->execute(['id' => $branchId]);
        self::assertNull($claim->invoke($service, $this->pdo, $retrySubscriptionId, $inactiveBranchEventKey));
        $inactiveBranch = $this->pdo->prepare(
            'SELECT status,last_error FROM push_delivery_log WHERE subscription_id=:id AND event_key=:event_key'
        );
        $inactiveBranch->execute(['id' => $retrySubscriptionId, 'event_key' => $inactiveBranchEventKey]);
        $inactiveBranchRow = $inactiveBranch->fetch();
        self::assertSame('failed', $inactiveBranchRow['status']);
        self::assertSame('TARGET_INACTIVE', $inactiveBranchRow['last_error']);
        $this->pdo->prepare('UPDATE branches SET is_active=1 WHERE id=:id')->execute(['id' => $branchId]);

        // The stock may be depleted after the outbox event is committed but
        // before the immediate sender claims it. Such an event must be retired.
        $this->pdo->prepare(
            'UPDATE stock SET quantity=0 WHERE branch_id=:branch_id AND product_id=:product_id'
        )->execute(['branch_id' => $branchId, 'product_id' => $productId]);
        $staleEventKey = 'restock-stale-' . bin2hex(random_bytes(4));
        $this->pdo->prepare(
            "INSERT INTO push_delivery_log (subscription_id,event_key,status)
             VALUES (:subscription_id,:event_key,'pending')"
        )->execute(['subscription_id' => $retrySubscriptionId, 'event_key' => $staleEventKey]);
        self::assertNull($claim->invoke($service, $this->pdo, $retrySubscriptionId, $staleEventKey));
        $stale = $this->pdo->prepare(
            'SELECT status,last_error FROM push_delivery_log WHERE subscription_id=:id AND event_key=:event_key'
        );
        $stale->execute(['id' => $retrySubscriptionId, 'event_key' => $staleEventKey]);
        $staleRow = $stale->fetch();
        self::assertSame('failed', $staleRow['status']);
        self::assertSame('STOCK_NO_LONGER_AVAILABLE', $staleRow['last_error']);
    }


    public function testGuestLoginReusesOneDeviceAccountWithoutDuplicateKeyRace(): void
    {
        $this->pdo->commit();
        $deviceUuid = sprintf(
            '%08x-%04x-4%03x-8%03x-%012x',
            random_int(0, 0xffffffff),
            random_int(0, 0xffff),
            random_int(0, 0xfff),
            random_int(0, 0xfff),
            random_int(0, 0xffffffffffff)
        );
        $service = new TokenService();
        $first = $service->issueGuestToken($deviceUuid);
        $second = $service->issueGuestToken($deviceUuid);

        self::assertSame($first['user_id'], $second['user_id']);
        self::assertNotSame($first['token'], $second['token']);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE device_uuid=:uuid');
        $count->execute(['uuid' => $deviceUuid]);
        self::assertSame(1, (int)$count->fetchColumn());

        $tokens = $this->pdo->prepare('SELECT token_hash FROM api_tokens WHERE user_id=:user_id ORDER BY id');
        $tokens->execute(['user_id' => $first['user_id']]);
        $hashes = $tokens->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(2, $hashes);
        self::assertNotSame($first['token'], $hashes[0]);
        self::assertSame($first['user_id'], (new TokenService())->authenticate($first['token'])['id']);
    }

    private function seedCatalog(): array
    {
        $suffix = bin2hex(random_bytes(8));
        $this->pdo->prepare('INSERT INTO branches (name,slug,is_active) VALUES (:name,:slug,1)')
            ->execute(['name' => 'Branch A', 'slug' => 'test-a-' . $suffix]);
        $branchOne = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO branches (name,slug,is_active) VALUES (:name,:slug,1)')
            ->execute(['name' => 'Branch B', 'slug' => 'test-b-' . $suffix]);
        $branchTwo = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO products (name,slug,is_active) VALUES (:name,:slug,1)')
            ->execute(['name' => 'Test product', 'slug' => 'test-product-' . $suffix]);
        return [$branchOne, $branchTwo, (int)$this->pdo->lastInsertId()];
    }

    private function capture(callable $callback): array
    {
        ob_start();
        try {
            $callback();
            $raw = (string)ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'Controller must return valid JSON.');
        return $decoded;
    }
}
