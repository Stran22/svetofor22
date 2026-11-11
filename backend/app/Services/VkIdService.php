<?php
declare(strict_types=1);

namespace Svet22\Services;

use RuntimeException;
use Svet22\Core\Config;

final class VkIdService
{
    public function exchange(string $code, string $deviceId, string $verifier, string $state): array
    {
        $clientId = (string) Config::get('vk_id.client_id', '');
        $redirectUri = (string) Config::get('vk_id.redirect_uri', '');
        if ($clientId === '' || $redirectUri === '') {
            throw new RuntimeException('VK ID is not configured.');
        }

        $result = $this->post('https://id.vk.ru/oauth2/auth', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'device_id' => $deviceId,
            'state' => $state,
        ]);
        if (!hash_equals($state, (string) ($result['state'] ?? ''))) {
            throw new RuntimeException('VK ID state mismatch.');
        }
        $accessToken = $result['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('VK ID did not return an access token.');
        }

        // Obtain the verified provider identity using the issued access token.
        $profile = $this->post('https://id.vk.ru/oauth2/user_info', [
            'client_id' => $clientId,
            'access_token' => $accessToken,
        ]);
        $user = $profile['user'] ?? null;
        $subject = is_array($user) ? ($user['user_id'] ?? null) : null;
        if ((!is_string($subject) && !is_int($subject)) || (string) $subject === '') {
            throw new RuntimeException('VK ID did not return a verified user id.');
        }
        $first = is_array($user) && is_string($user['first_name'] ?? null) ? $user['first_name'] : '';
        $last = is_array($user) && is_string($user['last_name'] ?? null) ? $user['last_name'] : '';
        return ['subject' => (string) $subject, 'name' => trim($first . ' ' . $last)];
    }

    private function post(string $url, array $params): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize VK ID request.');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        try {
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($ch);
        }
        if (!is_string($body) || $status < 200 || $status >= 300 || strlen($body) > 65536) {
            throw new RuntimeException('VK ID provider request failed.');
        }
        $json = json_decode($body, true);
        if (!is_array($json) || isset($json['error'])) {
            throw new RuntimeException('VK ID provider returned an error.');
        }
        return $json;
    }
}