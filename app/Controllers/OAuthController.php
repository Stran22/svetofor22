<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\OAuthService;
use App\Services\RateLimiter;
use App\Services\RequestUser;
use Throwable;

final class OAuthController
{
    public function start(Request $request): void
    {
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
        RateLimiter::hit('oauth_start_ip', $ip, 10, 900);
        $provider = strtolower(trim((string)($request->body['provider'] ?? '')));
        $user = RequestUser::optionalFromBearer($request);
        $result = (new OAuthService())->begin($provider, $user === null ? null : (int)$user['id']);
        Response::json(['success' => true, 'data' => $result]);
    }

    public function callback(Request $request): void
    {
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
        RateLimiter::hit('oauth_callback_ip', $ip, 30, 900);
        $service = new OAuthService();
        try {
            $returnUrl = $service->completeCallback($request->query);
            Response::redirect($returnUrl);
        } catch (Throwable $e) {
            if (!$e instanceof HttpException || $e->status >= 500) {
                error_log('OAuth callback failed: ' . $e->getMessage());
            }
            try {
                Response::redirect($service->failureReturnUrl());
            } catch (Throwable $redirectError) {
                error_log('OAuth return URL is not configured: ' . $redirectError->getMessage());
                Response::json(['success' => false, 'error' => [
                    'code' => 'OAUTH_FAILED',
                    'message' => 'Не удалось завершить авторизацию. Повторите попытку.',
                ]], 400);
            }
        }
    }

    public function exchange(Request $request): void
    {
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
        RateLimiter::hit('oauth_exchange_ip', $ip, 20, 900);
        $code = trim((string)($request->body['handoff_code'] ?? ''));
        $issued = (new OAuthService())->exchangeHandoffCode($code);
        Response::json(['success' => true, 'data' => [
            'token' => $issued['token'],
            'token_type' => $issued['token_type'],
            'expires_at' => $issued['expires_at'],
            'user_id' => $issued['user_id'],
            'account_type' => 'social',
        ]]);
    }
}
