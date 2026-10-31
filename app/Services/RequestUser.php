<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\HttpException;
use App\Core\Request;

final class RequestUser
{
    public static function optionalFromBearer(Request $request): ?array
    {
        $authorization = '';
        foreach ($request->headers as $name => $value) {
            if (strtolower((string)$name) === 'authorization') {
                $authorization = trim((string)$value);
                break;
            }
        }
        if ($authorization === '') return null;
        if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $authorization, $matches)) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Передан некорректный Bearer-токен.');
        }
        return (new TokenService())->authenticate($matches[1]);
    }

    public static function fromBearer(Request $request): array
    {
        $user = self::optionalFromBearer($request);
        if ($user === null) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Требуется Bearer-токен.');
        }
        return $user;
    }
}