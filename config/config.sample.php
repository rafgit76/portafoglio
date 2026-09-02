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
        'app_version' => '2.1.2',
        'public_app_name' => 'Portfolio Investimenti',
        'timezone' => 'Europe/Rome',
        'upload_max_size_mb' => 10,
        'show_runtime_summary' => false,
        'show_runtime_details' => false,
        'enable_fx' => true,
        'enable_news' => false,
        'external_fetch_timeout_seconds' => 3,
    ],
    'gemini' => [
        'api_key' => getenv('PORTFOLIO_GEMINI_API_KEY') ?: '',
        'model' => getenv('PORTFOLIO_GEMINI_MODEL') ?: 'gemini-3.8-flash',
        'thinking_level' => getenv('PORTFOLIO_GEMINI_THINKING_LEVEL') ?: 'medium',
        // General portfolio report with Gemini 3.8 Flash only.
        'general_thinking_level' => getenv('PORTFOLIO_GEMINI_GENERAL_THINKING_LEVEL') ?: 'low',
        'max_output_tokens' => (int) (getenv('PORTFOLIO_GEMINI_MAX_OUTPUT_TOKENS') ?: 20000),
        'timeout_seconds' => (int) (getenv('PORTFOLIO_GEMINI_TIMEOUT_SECONDS') ?: 90),
    ],
    'privacy' => [
        'pin_hash' => getenv('PORTFOLIO_PRIVACY_PIN_HASH') ?: '',
        'unlock_seconds' => (int) (getenv('PORTFOLIO_PRIVACY_UNLOCK_SECONDS') ?: 1800),
        'max_attempts' => (int) (getenv('PORTFOLIO_PRIVACY_MAX_ATTEMPTS') ?: 10),
        'lockout_seconds' => (int) (getenv('PORTFOLIO_PRIVACY_LOCKOUT_SECONDS') ?: 900),
    ],
];
