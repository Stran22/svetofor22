<?php
declare(strict_types=1);

// Run from the project root: php bin/check-firebase-auth.php
// This script never prints credentials or the OAuth access token.
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Config;
use App\Services\FirebaseAccessTokenService;

Config::load(dirname(__DIR__) . '/.env');

try {
    $token = (new FirebaseAccessTokenService())->getAccessToken();
    if ($token === '') throw new RuntimeException('Empty access token.');
    echo "Firebase OAuth: OK\n";
    echo "Project: " . Config::get('firebase.project_id', '') . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Firebase OAuth: FAILED\n");
    // Avoid printing exception details that could reveal local paths or server internals.
    exit(1);
}
