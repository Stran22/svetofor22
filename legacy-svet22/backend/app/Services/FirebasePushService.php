<?php
declare(strict_types=1);

namespace Svet22\Services;

use PDO;
use RuntimeException;
use Svet22\Core\Config;

/**
 * Sends an FCM notification to a single registered device.
 * No secrets, bearer tokens or FCM tokens are printed.
 */
final class FirebasePushService
{
    public function __construct(private PDO $db) {}

    public function sendToRegisteredTokenId(int $id, string $title, string $body): array
    {
        if ($id <= 0 || trim($title) === '' || trim($body) === '') {
            throw new RuntimeException('Invalid notification parameters.');
        }
        $stmt = $this->db->prepare('SELECT fcm_token FROM user_push_tokens WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $fcmToken = $stmt->fetchColumn();
        if (!is_string($fcmToken) || $fcmToken === '') {
            throw new RuntimeException('Registered FCM token not found.');
        }
        $projectId = (string) Config::get('firebase.project_id', '');
        if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $projectId)) {
            throw new RuntimeException('Invalid Firebase project ID.');
        }
        $accessToken = (new FirebaseAccessTokenService())->getAccessToken();
        $payload = json_encode([
            'message' => [
                'token' => $fcmToken,
                'notification' => ['title' => $title, 'body' => $body],
                'android' => ['priority' => 'high'],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $ch = curl_init('https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send');
        if ($ch === false) {
            throw new RuntimeException('Cannot initialize Firebase HTTP request.');
        }
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
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result = is_string($raw) ? json_decode($raw, true) : null;
        if ($httpStatus === 200 && is_array($result) && is_string($result['name'] ?? null)) {
            return ['success' => true, 'http_status' => $httpStatus];
        }
        // Do not expose Firebase's raw error text: it can contain token identifiers.
        $errorStatus = is_array($result) && is_array($result['error'] ?? null)
            ? (string) ($result['error']['status'] ?? 'UNKNOWN')
            : 'UNKNOWN';
        return [
            'success' => false,
            'http_status' => $httpStatus,
            'error_status' => preg_match('/^[A-Z_]+$/D', $errorStatus) ? $errorStatus : 'UNKNOWN',
        ];
    }
}
