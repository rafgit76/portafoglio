<?php
return [
    'db' => [
        'host' => getenv('PORTFOLIO_DB_HOST') ?: 'localhost',
        'port' => (int) (getenv('PORTFOLIO_DB_PORT') ?: 3306),
        'database' => getenv('PORTFOLIO_DB_NAME') ?: '',
        'username' => getenv('PORTFOLIO_DB_USER') ?: '',
        'password' => getenv('PORTFOLIO_DB_PASSWORD') ?: '',
        'charset' => getenv('PORTFOLIO_DB_CHARSET') ?: 'utf8mb4',
    ],
    'app' => [
        'base_path' => '/apps/portafoglio',
        'default_currency' => 'EUR',
        'target_php_version' => '8.4',
        'app_version' => '1.2.0',
        'public_app_name' => 'Portfolio Investimenti',
        'upload_max_size_mb' => 10,
        'show_runtime_summary' => false,
        'show_runtime_details' => false,
        'enable_fx' => true,
        'enable_news' => false,
        'external_fetch_timeout_seconds' => 3,
    ],
    'gemini' => [
        'api_key' => getenv('PORTFOLIO_GEMINI_API_KEY') ?: '',
        'model' => getenv('PORTFOLIO_GEMINI_MODEL') ?: 'gemini-2.5-flash',
        'timeout_seconds' => (int) (getenv('PORTFOLIO_GEMINI_TIMEOUT_SECONDS') ?: 90),
    ],
];
