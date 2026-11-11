<?php
declare(strict_types=1);

/**
 * Copy this file to a location outside public_html and set SVET_CONFIG_FILE
 * to its absolute path. Never commit real credentials.
 */
return [
    'app' => [
        'env' => 'production',
        'debug' => false,
        'timezone' => 'Asia/Barnaul',
        'base_url' => 'https://baza.altaj-otdyh.ru',
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'CHANGE_ME',
        'username' => 'CHANGE_ME',
        'password' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'token_bytes' => 32,
        'health_token' => '',
    ],
];
