<?php
declare(strict_types=1);
namespace Tests;

use App\Core\Request;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testRegisteredGetRouteIsDispatched(): void
    {
        $called = false;
        $router = new Router();
        $router->get('/api/v1/ping', static function (Request $request) use (&$called): void {
            $called = $request->method === 'GET';
        });
        $router->dispatch(new Request('GET', '/api/v1/ping', [], [], []));
        self::assertTrue($called);
    }
}
