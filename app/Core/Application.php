<?php
declare(strict_types=1);
namespace App\Core;

use App\Controllers\AdminAuthController;
use App\Controllers\AdminImportController;
use App\Controllers\AuthController;
use App\Controllers\CatalogController;
use App\Controllers\OAuthController;
use App\Controllers\PriceHistoryController;
use App\Controllers\ReviewsController;
use App\Controllers\SubscriptionController;
use App\Controllers\SystemController;
use Throwable;

final class Application
{
    public function run(): void
    {
        $router = new Router();
        $system = new SystemController();
        $catalog = new CatalogController();
        $auth = new AuthController();
        $adminAuth = new AdminAuthController();
        $adminImport = new AdminImportController();
        $oauth = new OAuthController();
        $priceHistory = new PriceHistoryController();
        $reviews = new ReviewsController();
        $subscriptions = new SubscriptionController();

        $router->get('/api/v1/health', [$system, 'health']);
        $router->get('/api/v1/branches', [$catalog, 'branches']);
        $router->get('/api/v1/categories', [$catalog, 'categories']);
        $router->get('/api/v1/products', [$catalog, 'products']);
        $router->get('/api/v1/products/detail', [$catalog, 'productDetail']);
        $router->get('/api/v1/products/price-history', [$priceHistory, 'index']);
        $router->get('/api/v1/products/reviews', [$reviews, 'index']);
        $router->post('/api/v1/products/reviews', [$reviews, 'save']);
        $router->get('/api/v1/subscriptions', [$subscriptions, 'index']);
        $router->post('/api/v1/subscriptions', [$subscriptions, 'create']);
        $router->post('/api/v1/subscriptions/cancel', [$subscriptions, 'cancel']);

        $router->post('/api/v1/auth/guest', [$auth, 'guest']);
        $router->get('/api/v1/auth/me', [$auth, 'me']);
        $router->post('/api/v1/auth/oauth/start', [$oauth, 'start']);
        $router->get('/api/v1/auth/oauth/callback', [$oauth, 'callback']);
        $router->post('/api/v1/auth/oauth/exchange', [$oauth, 'exchange']);

        $router->get('/api/v1/admin/csrf', [$adminAuth, 'csrf']);
        $router->post('/api/v1/admin/login', [$adminAuth, 'login']);
        $router->get('/api/v1/admin/me', [$adminAuth, 'me']);
        $router->post('/api/v1/admin/logout', [$adminAuth, 'logout']);
        $router->get('/api/v1/admin/imports', [$adminImport, 'index']);
        $router->post('/api/v1/admin/imports', [$adminImport, 'upload']);
        $router->get('/api/v1/admin/import-errors', [$adminImport, 'errors']);

        try {
            $router->dispatch(Request::fromGlobals());
        } catch (HttpException $e) {
            Response::json(['success' => false, 'error' => [
                'code' => $e->errorCode, 'message' => $e->getMessage(),
            ]], $e->status);
        } catch (Throwable $e) {
            error_log((string) $e);
            $error = ['code' => 'INTERNAL_ERROR', 'message' => 'Внутренняя ошибка сервера.'];
            if (Config::get('app.debug', false)) $error['debug'] = $e->getMessage();
            Response::json(['success' => false, 'error' => $error], 500);
        }
    }
}