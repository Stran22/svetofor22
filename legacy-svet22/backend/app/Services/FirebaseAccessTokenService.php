<?php
declare(strict_types=1);

namespace Svet22\Services;

use RuntimeException;
use Svet22\Core\Config;

/**
 * OAuth2 for Firebase HTTP v1 in the legacy Svet22 backend.
 * No secrets or access tokens are printed.
 */
final class FirebaseAccessTokenService
{
    public function getAccessToken(): string
    {
        $path = (string) Config::get('firebase.credentials_path', '');
        $project = (string) Config::get('firebase.project_id', '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Firebase credentials are not readable.');
        }
        $json = file_get_contents($path);
        $account = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($account) || ($account['type'] ?? null) !== 'service_account'
            || ($account['project_id'] ?? null) !== $project
            || !is_string($account['client_email'] ?? null)
            || !is_string($account['private_key'] ?? null)) {
            throw new RuntimeException('Firebase credentials do not match the configured project.');
        }
        if (!function_exists('openssl_sign') || !function_exists('curl_init')) {
            throw new RuntimeException('OpenSSL or cURL extension is unavailable.');
        }

        $b64 = static function (array $value): string {
            return rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        };
        $now = time();
        $unsigned = $b64(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $b64([
            'iss' => $account['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]);
        if (!openssl_sign($unsigned, $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Firebase JWT signing failed.');
        }
        $assertion = $unsigned . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
        $ch = curl_init('https://oauth2.googleapis.com/token');
        if ($ch === false) throw new RuntimeException('Google OAuth connection initialization failed.');
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
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $status !== 200) {
            throw new RuntimeException('Google OAuth exchange failed (HTTP ' . $status . ').');
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
            throw new RuntimeException('Google OAuth did not return an access token.');
        }
        return $data['access_token'];
    }
}
