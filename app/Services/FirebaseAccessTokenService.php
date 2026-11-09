<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Config;
use RuntimeException;

/**
 * Google OAuth2 service-account access token for FCM HTTP v1.
 * The private key never leaves the server except as a signed JWT assertion.
 */
final class FirebaseAccessTokenService
{
    public function getAccessToken(): string
    {
        $path = (string) Config::get('firebase.credentials_path', '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Firebase service-account file is missing or unreadable.');
        }
        $json = file_get_contents($path);
        $credentials = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($credentials) ||
            ($credentials['type'] ?? '') !== 'service_account' ||
            ($credentials['project_id'] ?? '') !== (string) Config::get('firebase.project_id', '') ||
            !is_string($credentials['client_email'] ?? null) ||
            !is_string($credentials['private_key'] ?? null)) {
            throw new RuntimeException('Firebase service-account credentials are invalid.');
        }
        if (!function_exists('openssl_sign') || !function_exists('curl_init')) {
            throw new RuntimeException('PHP OpenSSL and cURL extensions are required.');
        }

        $now = time();
        $encode = static fn(array $value): string => rtrim(strtr(
            base64_encode((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            '+/', '-_'
        ), '=');
        $header = $encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $payload = $encode([
            'iss' => $credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]);
        $unsigned = $header . '.' . $payload;
        if (!openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign Firebase service-account assertion.');
        }
        $assertion = $unsigned . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        $ch = curl_init('https://oauth2.googleapis.com/token');
        if ($ch === false) throw new RuntimeException('Could not initialize Google OAuth request.');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($response) || $status !== 200) {
            throw new RuntimeException('Google OAuth token exchange failed (HTTP ' . $status . ').');
        }
        $data = json_decode($response, true);
        if (!is_array($data) || !is_string($data['access_token'] ?? null) ||
            $data['access_token'] === '') {
            throw new RuntimeException('Google OAuth returned no access token.');
        }
        return $data['access_token'];
    }
}
