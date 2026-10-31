<?php
declare(strict_types=1);
namespace Tests;

use App\Controllers\CatalogController;
use App\Controllers\PriceHistoryController;
use App\Controllers\ReviewsController;
use App\Controllers\SubscriptionController;
use App\Core\Database;
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

        $history = $this->capture(fn() => (new PriceHistoryController())->index(
            new Request('GET', '/api/v1/products/price-history', [
                'product_id' => (string)$productId, 'branch_id' => (string)$branchTwo,
            ], [], [])
        ));
        self::assertSame('12.75', (string)$history['data']['history'][0]['price']);
        self::assertSame($branchTwo, (int)$history['data']['branch_id']);
    }

    public function testSubscriptionAndReviewQueriesRespectUserAndProductRelations(): void
    {
        [$branchOne, , $productId] = $this->seedCatalog();
        $this->pdo->prepare('INSERT INTO users (display_name) VALUES (:name)')
            ->execute(['name' => 'Integration test']);
        $userId = (int)$this->pdo->lastInsertId();
        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare(
            'INSERT INTO api_tokens (user_id,token_hash,expires_at) VALUES (:user_id,:hash,DATE_ADD(NOW(),INTERVAL 1 DAY))'
        )->execute(['user_id' => $userId, 'hash' => hash('sha256', $token)]);
        $requestHeaders = ['Authorization' => 'Bearer ' . $token];

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
