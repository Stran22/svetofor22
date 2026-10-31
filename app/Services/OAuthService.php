<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use Throwable;

final class OAuthService
{
    private const VK_AUTHORIZE_URL = 'https://id.vk.ru/authorize';
    private const VK_TOKEN_URL = 'https://id.vk.ru/oauth2/auth';
    private const VK_USER_INFO_URL = 'https://id.vk.ru/oauth2/user_info';
    private const YANDEX_AUTHORIZE_URL = 'https://oauth.yandex.ru/authorize';
    private const YANDEX_TOKEN_URL = 'https://oauth.yandex.ru/token';
    private const YANDEX_USER_INFO_URL = 'https://login.yandex.ru/info';

    public static function isAllowedProvider(string $provider): bool
    {
        return in_array($provider, ['vk', 'ok', 'yandex'], true);
    }

    public static function createPkcePair(): array
    {
        $verifier = self::base64UrlEncode(random_bytes(64));
        return [
            'verifier' => $verifier,
            'challenge' => self::base64UrlEncode(hash('sha256', $verifier, true)),
        ];
    }

    public function begin(string $provider, ?int $linkUserId): array
    {
        if (!self::isAllowedProvider($provider)) {
            throw new HttpException(422, 'OAUTH_PROVIDER_INVALID', 'Неизвестный провайдер авторизации.');
        }
        $clientId = $provider === 'yandex'
            ? (string)Config::get('oauth.yandex.client_id', '')
            : (string)Config::get('oauth.vk_id.client_id', '');
        if ($clientId === '') {
            throw new HttpException(503, 'OAUTH_NOT_CONFIGURED', 'Авторизация через выбранный сервис ещё не настроена.');
        }

        $appUrl = rtrim((string)Config::get('app.url', ''), '/');
        if (!filter_var($appUrl, FILTER_VALIDATE_URL) || parse_url($appUrl, PHP_URL_SCHEME) !== 'https') {
            throw new HttpException(503, 'OAUTH_REDIRECT_NOT_CONFIGURED', 'Для OAuth необходимо настроить публичный HTTPS-адрес сайта.');
        }
        $redirectUri = $appUrl . '/api/v1/auth/oauth/callback';
        $state = self::base64UrlEncode(random_bytes(32));
        $pkce = self::createPkcePair();
        $deviceId = 'sv22-' . bin2hex(random_bytes(12));
        $expiresAt = (new \DateTimeImmutable('+10 minutes'))->format('Y-m-d H:i:s');

        $stmt = Database::connection()->prepare(
            'INSERT INTO oauth_attempts
             (provider,state_hash,code_verifier,redirect_uri,device_id,link_user_id,expires_at)
             VALUES (:provider,:state_hash,:verifier,:redirect_uri,:device_id,:link_user_id,:expires_at)'
        );
        $stmt->execute([
            'provider' => $provider,
            'state_hash' => hash('sha256', $state),
            'verifier' => $pkce['verifier'],
            'redirect_uri' => $redirectUri,
            'device_id' => $deviceId,
            'link_user_id' => $linkUserId,
            'expires_at' => $expiresAt,
        ]);

        if ($provider === 'yandex') {
            $params = [
                'response_type' => 'code',
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'state' => $state,
                'device_id' => $deviceId,
                'device_name' => 'Svetofor22 Android',
                'scope' => (string)Config::get('oauth.yandex.scope', 'login:info login:email login:avatar'),
                'code_challenge' => $pkce['challenge'],
                'code_challenge_method' => 'S256',
            ];
            $authorizationUrl = self::YANDEX_AUTHORIZE_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        } else {
            $params = [
                'response_type' => 'code',
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'state' => $state,
                'code_challenge' => $pkce['challenge'],
                'code_challenge_method' => 'S256',
                'scope' => (string)Config::get('oauth.vk_id.scope', 'vkid.personal_info email'),
            ];
            if ($provider === 'ok') $params['provider'] = 'ok_ru';
            $authorizationUrl = self::VK_AUTHORIZE_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        return ['provider' => $provider, 'authorization_url' => $authorizationUrl, 'state' => $state, 'expires_at' => $expiresAt];
    }

    public function completeCallback(array $query): string
    {
        $state = trim((string)($query['state'] ?? ''));
        $code = trim((string)($query['code'] ?? ''));
        if ($state === '' || strlen($state) > 1024) {
            throw new HttpException(400, 'OAUTH_STATE_INVALID', 'Проверка OAuth state не пройдена.');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT id,provider,code_verifier,redirect_uri,device_id,link_user_id
                 FROM oauth_attempts
                 WHERE state_hash=:state_hash AND expires_at>NOW() AND consumed_at IS NULL
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['state_hash' => hash('sha256', $state)]);
            $attempt = $stmt->fetch();
            if (!$attempt) {
                throw new HttpException(400, 'OAUTH_STATE_INVALID', 'Проверка OAuth state не пройдена или срок авторизации истёк.');
            }
            $pdo->prepare('UPDATE oauth_attempts SET consumed_at=NOW(),code_verifier=NULL WHERE id=:id')->execute(['id' => $attempt['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        if (!empty($query['error'])) {
            throw new HttpException(401, 'OAUTH_ACCESS_DENIED', 'Пользователь отменил вход через социальную сеть.');
        }
        if ($code === '' || strlen($code) > 4096) {
            throw new HttpException(400, 'OAUTH_CODE_INVALID', 'Провайдер не вернул корректный код авторизации.');
        }

        $provider = (string)$attempt['provider'];
        $deviceId = $provider === 'yandex' ? (string)$attempt['device_id'] : trim((string)($query['device_id'] ?? ''));
        if ($deviceId === '' || strlen($deviceId) > 100) {
            throw new HttpException(400, 'OAUTH_DEVICE_ID_MISSING', 'Провайдер не вернул идентификатор устройства.');
        }

        $tokens = $this->exchangeCode($provider, $code, (string)$attempt['code_verifier'], (string)$attempt['redirect_uri'], $deviceId, $state);
        $profile = $this->fetchProfile($provider, (string)$tokens['access_token']);
        $handoffCode = $this->createOrLinkAccount(
            $provider,
            $profile['provider_user_id'],
            $profile['display_name'],
            $profile['email'],
            $attempt['link_user_id'] === null ? null : (int)$attempt['link_user_id']
        );
        return $this->appReturnUrl(['handoff_code' => $handoffCode]);
    }

    public function exchangeHandoffCode(string $plainCode): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $plainCode)) {
            throw new HttpException(401, 'OAUTH_HANDOFF_INVALID', 'Код авторизации недействителен или уже использован.');
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT id,user_id FROM oauth_handoff_codes
                 WHERE code_hash=:hash AND expires_at>NOW() AND consumed_at IS NULL
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['hash' => hash('sha256', $plainCode)]);
            $handoff = $stmt->fetch();
            if (!$handoff) {
                throw new HttpException(401, 'OAUTH_HANDOFF_INVALID', 'Код авторизации недействителен или уже использован.');
            }
            $pdo->prepare('UPDATE oauth_handoff_codes SET consumed_at=NOW() WHERE id=:id')->execute(['id' => $handoff['id']]);
            $issued = (new TokenService())->issueTokenForUser((int)$handoff['user_id']);
            $pdo->commit();
            return $issued;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public function failureReturnUrl(): string
    {
        return $this->appReturnUrl(['error' => 'oauth_failed']);
    }

    private function exchangeCode(string $provider, string $code, string $verifier, string $redirectUri, string $deviceId, string $state): array
    {
        if ($provider === 'yandex') {
            $body = [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'client_id' => (string)Config::get('oauth.yandex.client_id', ''),
                'device_id' => $deviceId,
                'code_verifier' => $verifier,
            ];
            $response = $this->httpRequest(self::YANDEX_TOKEN_URL, $body);
        } else {
            $body = [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'code_verifier' => $verifier,
                'redirect_uri' => $redirectUri,
                'client_id' => (string)Config::get('oauth.vk_id.client_id', ''),
                'device_id' => $deviceId,
                'state' => $state,
            ];
            $serviceToken = (string)Config::get('oauth.vk_id.service_token', '');
            if ($serviceToken !== '') $body['service_token'] = $serviceToken;
            $response = $this->httpRequest(self::VK_TOKEN_URL, $body);
        }
        if (empty($response['access_token']) || !is_string($response['access_token'])) {
            throw new HttpException(502, 'OAUTH_TOKEN_EXCHANGE_FAILED', 'Не удалось обменять код авторизации на токен.');
        }
        return $response;
    }

    private function fetchProfile(string $provider, string $accessToken): array
    {
        if ($provider === 'yandex') {
            $data = $this->httpRequest(self::YANDEX_USER_INFO_URL . '?format=json', [], ['Authorization: OAuth ' . $accessToken], false);
            $providerUserId = (string)($data['id'] ?? $data['uid'] ?? '');
            $displayName = (string)($data['display_name'] ?? $data['real_name'] ?? $data['login'] ?? '');
            $email = isset($data['default_email']) && is_string($data['default_email']) ? $data['default_email'] : null;
        } else {
            $data = $this->httpRequest(self::VK_USER_INFO_URL, [
                'client_id' => (string)Config::get('oauth.vk_id.client_id', ''),
                'access_token' => $accessToken,
            ]);
            $user = is_array($data['user'] ?? null) ? $data['user'] : $data;
            $providerUserId = (string)($user['user_id'] ?? $user['id'] ?? '');
            $firstName = trim((string)($user['first_name'] ?? ''));
            $lastName = trim((string)($user['last_name'] ?? ''));
            $displayName = trim($firstName . ' ' . $lastName);
            $email = isset($user['email']) && is_string($user['email']) ? $user['email'] : null;
        }
        if ($providerUserId === '' || strlen($providerUserId) > 190) {
            throw new HttpException(502, 'OAUTH_PROFILE_INVALID', 'Провайдер не вернул корректный постоянный идентификатор пользователя.');
        }
        $displayName = trim((string)$displayName);
        $displayName = $displayName === '' ? null : mb_substr($displayName, 0, 150);
        $email = is_string($email) && strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL)
            ? $email
            : null;
        return [
            'provider_user_id' => $providerUserId,
            'display_name' => $displayName,
            'email' => $email,
        ];
    }

    private function createOrLinkAccount(string $provider, string $providerUserId, ?string $displayName, ?string $email, ?int $linkUserId): string
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $find = $pdo->prepare(
                'SELECT user_id FROM social_accounts
                 WHERE provider=:provider AND provider_user_id=:provider_user_id
                 LIMIT 1 FOR UPDATE'
            );
            $find->execute(['provider' => $provider, 'provider_user_id' => $providerUserId]);
            $existingUserId = $find->fetchColumn();

            if ($existingUserId !== false) {
                $userId = (int)$existingUserId;
                if ($linkUserId !== null && $userId !== $linkUserId) {
                    throw new HttpException(409, 'SOCIAL_ACCOUNT_ALREADY_LINKED', 'Этот социальный аккаунт уже привязан к другой учётной записи.');
                }
            } else {
                if ($linkUserId !== null) {
                    $checkUser = $pdo->prepare('SELECT id FROM users WHERE id=:id LIMIT 1');
                    $checkUser->execute(['id' => $linkUserId]);
                    if (!$checkUser->fetchColumn()) throw new HttpException(404, 'USER_NOT_FOUND', 'Учётная запись для привязки не найдена.');
                    $userId = $linkUserId;
                } else {
                    $insertUser = $pdo->prepare('INSERT INTO users (display_name,email) VALUES (:name,:email)');
                    $insertUser->execute(['name' => $displayName, 'email' => $email]);
                    $userId = (int)$pdo->lastInsertId();
                }
                $pdo->prepare(
                    'INSERT INTO social_accounts (user_id,provider,provider_user_id)
                     VALUES (:user_id,:provider,:provider_user_id)'
                )->execute([
                    'user_id' => $userId,
                    'provider' => $provider,
                    'provider_user_id' => $providerUserId,
                ]);
            }

            $pdo->prepare(
                "UPDATE users SET
                   display_name=COALESCE(NULLIF(display_name,''),:display_name),
                   email=COALESCE(NULLIF(email,''),:email)
                 WHERE id=:id"
            )->execute(['display_name' => $displayName, 'email' => $email, 'id' => $userId]);

            $plainCode = bin2hex(random_bytes(32));
            $pdo->prepare(
                'INSERT INTO oauth_handoff_codes (user_id,code_hash,expires_at)
                 VALUES (:user_id,:code_hash,DATE_ADD(NOW(), INTERVAL 2 MINUTE))'
            )->execute(['user_id' => $userId, 'code_hash' => hash('sha256', $plainCode)]);
            $pdo->commit();
            return $plainCode;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private function httpRequest(string $url, array $body = [], array $headers = [], bool $post = true): array
    {
        if (!function_exists('curl_init')) throw new HttpException(503, 'OAUTH_HTTP_UNAVAILABLE', 'На сервере не включено расширение PHP cURL.');
        $ch = curl_init($url);
        if ($ch === false) throw new HttpException(502, 'OAUTH_HTTP_FAILED', 'Не удалось подготовить запрос к провайдеру.');
        $requestHeaders = array_merge(['Accept: application/json'], $headers);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($post) {
            $requestHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($body, '', '&', PHP_QUERY_RFC3986);
        } else {
            $options[CURLOPT_HTTPGET] = true;
        }
        $options[CURLOPT_HTTPHEADER] = $requestHeaders;
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $status < 200 || $status >= 300) {
            throw new HttpException(502, 'OAUTH_PROVIDER_UNAVAILABLE', 'Сервис авторизации временно недоступен.');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || isset($decoded['error'])) {
            throw new HttpException(502, 'OAUTH_PROVIDER_RESPONSE_INVALID', 'Сервис авторизации вернул ошибку.');
        }
        return $decoded;
    }

    private function appReturnUrl(array $params): string
    {
        $base = (string)Config::get('app.auth_return_uri', '');
        $parts = parse_url($base);
        if ($base === '' || !is_array($parts) || ($parts['scheme'] ?? '') !== 'svetofor2' || ($parts['host'] ?? '') !== 'oauth' || ($parts['path'] ?? '') !== '/callback' || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new HttpException(503, 'OAUTH_APP_RETURN_NOT_CONFIGURED', 'Не настроен адрес возврата в Android-приложение.');
        }
        $separator = str_contains($base, '?') ? '&' : '?';
        return $base . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}