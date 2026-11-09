<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Config;
use RuntimeException;

/** FCM HTTP v1 sender. Never logs credentials or device tokens. */
final class FcmSender
{
    public function send(string $deviceToken, string $title, string $body, int $productId, int $branchId): array
    {
        $project = (string)Config::get('firebase.project_id', '');
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $project)) {
            throw new RuntimeException('Firebase project ID is not configured.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required.');
        }
        $accessToken = (new FirebaseAccessTokenService())->getAccessToken();
        $payload = json_encode(['message' => [
            'token' => $deviceToken,
            'notification' => ['title' => $title, 'body' => $body],
            'data' => [
                'type' => 'product_available',
                'product_id' => (string)$productId,
                'branch_id' => (string)$branchId,
            ],
            'android' => ['priority' => 'high'],
        ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $ch = curl_init('https://fcm.googleapis.com/v1/projects/' . $project . '/messages:send');
        if ($ch === false) throw new RuntimeException('Cannot initialize FCM request.');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json; charset=utf-8',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        if ($status === 200 && is_array($json) && is_string($json['name'] ?? null)) {
            return ['success' => true, 'message_id' => $json['name']];
        }
        $error = is_array($json) ? ($json['error'] ?? []) : [];
        $reason = is_array($error) ? (string)($error['status'] ?? 'UNKNOWN') : 'UNKNOWN';
        return [
            'success' => false,
            'http_status' => $status,
            'reason' => preg_match('/^[A-Z_]+$/D', $reason) ? $reason : 'UNKNOWN',
        ];
    }
}
