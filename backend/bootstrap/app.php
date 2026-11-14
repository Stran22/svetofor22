<?php
declare(strict_types=1);

use Svet22\Controllers\AdminController;
use Svet22\Controllers\AdminReviewsController;
use Svet22\Controllers\ApiController;
use Svet22\Controllers\ReviewController;
use Svet22\Controllers\PushController;
use Svet22\Controllers\SubscriptionController;
use Svet22\Controllers\VkAuthController;
use Svet22\Core\Database;
use Svet22\Core\Request;
use Svet22\Core\Response;
use Svet22\Core\Router;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$request = new Request();

// Liveness-проверка не зависит от доступности MySQL.
if ($request->method() === 'GET' && $request->path() === '/api/v1/health') {
    Response::success(['status' => 'ok', 'service' => 'svet22-api']);
}

$router = new Router();

try {
    $db = Database::connection();
    $api = new ApiController($db);
    $admin = new AdminController($db);
    $adminReviews = new AdminReviewsController($db);
    $reviews = new ReviewController($db);
    $push = new PushController($db);
    $subscriptions = new SubscriptionController($db);
    $vkAuth = new VkAuthController($db);

    $router->get('/api/v1/health/db', [$api, 'healthDb']);
    $router->get('/api/v1/branches', [$api, 'branches']);
    $router->get('/api/v1/categories', [$api, 'categories']);
    $router->get('/api/v1/products', [$api, 'products']);
    $router->get('/api/v1/products/{id}', [$api, 'product']);
    $router->get('/api/v1/products/{id}/reviews', [$reviews, 'index']);
    $router->post('/api/v1/products/{id}/reviews', [$reviews, 'create']);
    $router->post('/api/v1/auth/device', [$api, 'registerDevice']);
    $router->post('/api/v1/auth/vk', [$vkAuth, 'login']);
    $router->post('/api/v1/push/register', [$push, 'register']);
    $router->post('/api/v1/push/unregister', [$push, 'unregister']);
    $router->get('/api/v1/subscriptions', [$subscriptions, 'index']);
    $router->post('/api/v1/subscriptions', [$subscriptions, 'create']);
    $router->post('/api/v1/subscriptions/cancel', [$subscriptions, 'cancel']);
    $router->get('/api/v1/me', [$api, 'me']);
    $router->post('/api/v1/auth/logout', [$api, 'logout']);
	$router->get('/api/v1/me/reviews', [$reviews, 'mine']);

    // Веб-панель администратора. Все изменения выполняются POST + CSRF.
    $router->get('/admin/login', [$admin, 'loginPage']);
    $router->post('/admin/login', [$admin, 'login']);
    $router->post('/admin/logout', [$admin, 'logout']);
    $router->get('/admin', [$admin, 'dashboard']);
    $router->get('/admin/import', [$admin, 'importPage']);
    $router->post('/admin/import/preview', [$admin, 'previewImport']);
    $router->post('/admin/import/confirm', [$admin, 'import']);
    $router->get('/admin/import/mapping', [$admin, 'mappingPage']);
    $router->post('/admin/import/mapping/preview', [$admin, 'previewMapping']);
    $router->post('/admin/import/mapping/save', [$admin, 'saveMapping']);
    $router->get('/admin/imports', [$admin, 'imports']);
    $router->get('/admin/imports/{id}', [$admin, 'importDetail']);
    $router->get('/admin/reviews', [$adminReviews, 'index']);
    $router->post('/admin/reviews/{id}/moderate', [$adminReviews, 'moderate']);
    $router->get('/admin/products', [$admin, 'products']);
    $router->get('/admin/products/{id}', [$admin, 'productEdit']);
    $router->post('/admin/products/{id}', [$admin, 'updateProduct']);
    $router->post('/admin/products/{id}/photo', [new \Svet22\Controllers\AdminProductImageController($db), 'upload']);
    $router->get('/admin/categories', [$admin, 'categories']);
    $router->post('/admin/categories', [$admin, 'createCategory']);
    $router->post('/admin/categories/{id}/toggle', [$admin, 'toggleCategory']);
    $router->get('/admin/branches', [$admin, 'branches']);
    $router->post('/admin/branches', [$admin, 'createBranch']);
    $router->post('/admin/branches/{id}/toggle', [$admin, 'toggleBranch']);
    $router->get('/admin/users', [$admin, 'users']);
    $router->post('/admin/users', [$admin, 'createUser']);
    $router->post('/admin/users/{id}/toggle', [$admin, 'toggleUser']);

    $router->dispatch($request);
} catch (Throwable $e) {
    error_log('[svet22] ' . $e::class . ': ' . $e->getMessage());
    if (str_starts_with($request->path(), '/admin')) {
        Response::html('<!doctype html><html lang="ru"><meta charset="utf-8"><title>Ошибка</title><h1>Панель временно недоступна</h1><p>Проверьте настройки сервера или обратитесь к администратору.</p></html>', 500);
    }
    Response::error('INTERNAL_ERROR', 'Внутренняя ошибка сервера.', 500);
}