<?php
$localConfigPath = __DIR__ . '/config.local.php';
$localConfig = is_file($localConfigPath) ? require $localConfigPath : [];

$env = static function (string $key, mixed $default = null): mixed {
    $value = getenv($key);
    return $value === false || $value === '' ? $default : $value;
};

$config = [
    'db' => [
        'host' => $env('PORTFOLIO_DB_HOST', 'localhost'),
        'port' => (int) $env('PORTFOLIO_DB_PORT', 3306),
        'database' => $env('PORTFOLIO_DB_NAME', ''),
        'username' => $env('PORTFOLIO_DB_USER', ''),
        'password' => $env('PORTFOLIO_DB_PASSWORD', ''),
        'charset' => $env('PORTFOLIO_DB_CHARSET', 'utf8mb4'),
    ],
    'app' => [
        'base_path' => $env('PORTFOLIO_BASE_PATH', '/apps/portafoglio'),
        'default_currency' => 'EUR',
        'target_php_version' => '8.4',
        'app_version' => '1.3.3',
        'public_app_name' => 'Portfolio Investimenti',
        'upload_max_size_mb' => 10,
        'show_runtime_summary' => false,
        'show_runtime_details' => false,
        'enable_fx' => true,
        'enable_news' => false,
        'external_fetch_timeout_seconds' => 3,
    ],
    'gemini' => [
        'api_key' => $env('PORTFOLIO_GEMINI_API_KEY', ''),
        'model' => $env('PORTFOLIO_GEMINI_MODEL', 'gemini-3.1-flash-lite'),
        'timeout_seconds' => (int) $env('PORTFOLIO_GEMINI_TIMEOUT_SECONDS', 90),
    ],
];

return array_replace_recursive($config, is_array($localConfig) ? $localConfig : []);
