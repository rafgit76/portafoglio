<?php

return [
    'db' => [
        'host' => '31.11.39.163',
        'port' => 3306,
        'database' => 'YOUR_DB_NAME',
        'username' => 'YOUR_DB_USER',
        'password' => 'YOUR_DB_PASSWORD',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'base_path' => '/apps/portafoglio',
        'app_version' => '1.2.5',
        'enable_fx' => true,
        'enable_news' => false,
    ],
    'gemini' => [
        'api_key' => 'YOUR_GEMINI_API_KEY',
        'model' => 'gemini-3.1-flash-lite',
        'timeout_seconds' => 90,
    ],
];
