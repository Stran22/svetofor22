<?php
declare(strict_types=1);
namespace Tests;

use App\Core\Request;
use App\Services\RequestUser;
use App\Core\HttpException;
use PHPUnit\Framework\TestCase;

final class RequestUserTest extends TestCase
{
    public function testExtractsBearerTokenCaseInsensitively(): void
    {
        $token = str_repeat('a', 64);
        $request = new Request('POST', '/api/v1/auth/logout', [], ['authorization' => 'Bearer ' . $token], []);
        self::assertSame($token, RequestUser::tokenFromBearer($request));
    }

    public function testRejectsMissingBearerToken(): void
    {
        $request = new Request('POST', '/api/v1/auth/logout', [], [], []);
        $this->expectException(HttpException::class);
        RequestUser::tokenFromBearer($request);
    }

    public function testRejectsMalformedBearerToken(): void
    {
        $request = new Request('POST', '/api/v1/auth/logout', [], ['Authorization' => 'Bearer not-a-token'], []);
        $this->expectException(HttpException::class);
        RequestUser::tokenFromBearer($request);
    }
}
