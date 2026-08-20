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
        'app_version' => '2.2.0',
        'timezone' => 'Europe/Rome',
        'enable_fx' => true,
        'enable_news' => false,
        // Lascia null per non applicare una soglia arbitraria di riconciliazione.
        'account_reconciliation_warn_pct' => null,
    ],
    'gemini' => [
        'api_key' => 'YOUR_GEMINI_API_KEY',
        'model' => 'gemini-3.7-flash',
        'thinking_level' => 'medium',
        'max_output_tokens' => 20000,
        'timeout_seconds' => 90,
    ],
    'privacy' => [
        // Genera l'hash con: php -r "echo password_hash('1234', PASSWORD_DEFAULT);"
        'pin_hash' => 'YOUR_PASSWORD_HASH',
        'unlock_seconds' => 1800,
        'max_attempts' => 10,
        'lockout_seconds' => 900,
    ],
];
