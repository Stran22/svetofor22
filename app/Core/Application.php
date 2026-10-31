<?php
declare(strict_types=1);
namespace App\Core;

use App\Controllers\AdminAuthController;
use App\Controllers\AuthController;
use App\Controllers\CatalogController;
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
        $router->get('/api/v1/health', [$system, 'health']);
        $router->get('/api/v1/branches', [$catalog, 'branches']);
        $router->get('/api/v1/categories', [$catalog, 'categories']);
        $router->get('/api/v1/products', [$catalog, 'products']);
        $router->get('/api/v1/products/detail', [$catalog, 'productDetail']);
        $router->post('/api/v1/auth/guest', [$auth, 'guest']);
        $router->get('/api/v1/auth/me', [$auth, 'me']);
        $router->get('/api/v1/admin/csrf', [$adminAuth, 'csrf']);
        $router->post('/api/v1/admin/login', [$adminAuth, 'login']);
        $router->get('/api/v1/admin/me', [$adminAuth, 'me']);
        $router->post('/api/v1/admin/logout', [$adminAuth, 'logout']);
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