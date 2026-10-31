<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\TokenService;

final class AuthController
{
    public function guest(Request $request): void
    {
        $uuid = strtolower(trim((string)($request->body['device_uuid'] ?? '')));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid)) {
            throw new HttpException(422, 'VALIDATION_ERROR', 'Передан некорректный device_uuid.');
        }
        $issued = (new TokenService())->issueGuestToken($uuid);
        Response::json(['success' => true, 'data' => $issued], 201);
    }

    public function me(Request $request): void
    {
        $authorization = '';
        foreach ($request->headers as $name => $value) {
            if (strtolower((string)$name) === 'authorization') { $authorization = trim((string)$value); break; }
        }
        if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', $authorization, $matches)) {
            throw new HttpException(401, 'UNAUTHENTICATED', 'Требуется Bearer-токен.');
        }
        $user = (new TokenService())->authenticate($matches[1]);
        Response::json(['success' => true, 'data' => ['user' => $user, 'account_type' => 'guest']]);
    }
}
