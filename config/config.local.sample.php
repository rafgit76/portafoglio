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
        'app_version' => '1.5.4',
        'timezone' => 'Europe/Rome',
        'enable_fx' => true,
        'enable_news' => false,
    ],
    'gemini' => [
        'api_key' => 'YOUR_GEMINI_API_KEY',
        'model' => 'gemini-3.5-flash-lite',
        'thinking_level' => 'medium',
        'max_output_tokens' => 20000,
        'timeout_seconds' => 90,
    ],
];
