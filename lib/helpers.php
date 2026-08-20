<?php

function app_config(): array
{
    static $cfg;

    if ($cfg === null) {
        $cfgPath = __DIR__ . '/../config/config.php';
        if (!file_exists($cfgPath)) {
            http_response_code(500);
            exit('Config non trovata: config/config.php');
        }
        $cfg = require $cfgPath;
    }

    return $cfg;
}

function app_setting(string $key, mixed $default = null): mixed
{
    $appConfig = app_config()['app'] ?? [];

    return $appConfig[$key] ?? $default;
}

function app_base_path(): string
{
    return rtrim(app_config()['app']['base_path'] ?? '', '/');
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    header('Location: ' . app_base_path() . $path);
    exit;
}

function set_flash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function get_flash(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return $flash;
}

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function is_valid_csrf_token(?string $token): bool
{
    return is_string($token) && $token !== '' && hash_equals(csrf_token(), $token);
}

function privacy_config(): array
{
    $config = app_config()['privacy'] ?? [];

    return [
        'pin_hash' => (string) ($config['pin_hash'] ?? ''),
        'unlock_seconds' => max(60, (int) ($config['unlock_seconds'] ?? 1800)),
        'max_attempts' => max(10, (int) ($config['max_attempts'] ?? 10)),
        'lockout_seconds' => max(60, (int) ($config['lockout_seconds'] ?? 900)),
    ];
}

function privacy_is_configured(): bool
{
    $hash = privacy_config()['pin_hash'];
    if ($hash === '') {
        return false;
    }

    $info = password_get_info($hash);
    return ($info['algoName'] ?? 'unknown') !== 'unknown';
}

function privacy_is_unlocked(bool $touchActivity = true): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $lastActivity = (int) ($_SESSION['privacy_last_activity'] ?? 0);
    $timeout = privacy_config()['unlock_seconds'];
    if ($lastActivity <= 0 || (time() - $lastActivity) > $timeout) {
        unset($_SESSION['privacy_last_activity']);
        return false;
    }

    if ($touchActivity) {
        $_SESSION['privacy_last_activity'] = time();
    }

    return true;
}

function privacy_unlock_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    session_regenerate_id(true);
    $_SESSION['privacy_last_activity'] = time();
}

function privacy_lock_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    unset($_SESSION['privacy_last_activity']);
    session_regenerate_id(true);
}

function privacy_public_scripts(): array
{
    return ['index.php', 'changelog.php'];
}

function privacy_current_script(): string
{
    return basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
}

function privacy_current_relative_uri(): string
{
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/dashboard.php');
    $basePath = app_base_path();
    if ($basePath !== '' && str_starts_with($requestUri, $basePath)) {
        $requestUri = substr($requestUri, strlen($basePath));
    }

    return privacy_safe_return_path($requestUri);
}

function privacy_safe_return_path(?string $path): string
{
    $fallback = '/dashboard.php';
    if (!is_string($path) || $path === '' || !str_starts_with($path, '/') || str_starts_with($path, '//')) {
        return $fallback;
    }

    $parts = parse_url($path);
    if (!is_array($parts)) {
        return $fallback;
    }

    $script = basename((string) ($parts['path'] ?? ''));
    $allowedScripts = [
        'dashboard.php',
        'andamento.php',
        'upload.php',
        'snapshots.php',
        'snapshot.php',
        'compare.php',
        'assets.php',
        'asset.php',
        'account.php',
        'account_history.php',
        'credit_lines.php',
        'dossiers.php',
        'earnings.php',
        'earnings_event.php',
        'ai_report.php',
        'ai_report_view.php',
    ];
    if (!in_array($script, $allowedScripts, true)) {
        return $fallback;
    }

    $safe = '/' . $script;
    $query = (string) ($parts['query'] ?? '');
    if ($query !== '' && !str_contains($query, "\r") && !str_contains($query, "\n")) {
        $safe .= '?' . $query;
    }

    return $safe;
}

function privacy_require_unlocked(): void
{
    if (in_array(privacy_current_script(), privacy_public_scripts(), true)) {
        return;
    }

    if (privacy_is_configured() && privacy_is_unlocked()) {
        return;
    }

    $returnPath = privacy_current_relative_uri();
    redirect('/index.php?unlock=1&return=' . rawurlencode($returnPath));
}

function privacy_attempt_store_path(): string
{
    return __DIR__ . '/../storage/privacy_attempts.json';
}

function privacy_client_key(): string
{
    $address = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $key = privacy_config()['pin_hash'];
    if ($key === '') {
        $key = __FILE__;
    }

    return hash_hmac('sha256', $address, $key);
}

function privacy_attempt_store_update(callable $callback): mixed
{
    $path = privacy_attempt_store_path();
    ensure_directory(dirname($path));
    $handle = @fopen($path, 'c+');
    if (!is_resource($handle)) {
        throw new RuntimeException('Impossibile accedere al registro dei tentativi PIN.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Impossibile bloccare il registro dei tentativi PIN.');
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($data)) {
            $data = [];
        }

        $now = time();
        foreach ($data as $key => $entry) {
            $lastFailure = (int) ($entry['last_failure'] ?? 0);
            $lockedUntil = (int) ($entry['locked_until'] ?? 0);
            if ($lastFailure < ($now - 86400) && $lockedUntil <= $now) {
                unset($data[$key]);
            }
        }

        $result = $callback($data, $now);
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('Impossibile aggiornare il registro dei tentativi PIN.');
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $encoded . "\n");
        fflush($handle);
        flock($handle, LOCK_UN);

        return $result;
    } finally {
        fclose($handle);
    }
}

function privacy_attempt_status(): array
{
    $clientKey = privacy_client_key();
    $config = privacy_config();

    return privacy_attempt_store_update(static function (array &$data, int $now) use ($clientKey, $config): array {
        $entry = is_array($data[$clientKey] ?? null) ? $data[$clientKey] : [];
        $lockedUntil = (int) ($entry['locked_until'] ?? 0);
        if ($lockedUntil > 0 && $lockedUntil <= $now) {
            unset($data[$clientKey]);
            $entry = [];
            $lockedUntil = 0;
        }

        $failures = (int) ($entry['failures'] ?? 0);
        return [
            'locked' => $lockedUntil > $now,
            'locked_until' => $lockedUntil,
            'remaining_attempts' => max(0, $config['max_attempts'] - $failures),
        ];
    });
}

function privacy_record_failure(): array
{
    $clientKey = privacy_client_key();
    $config = privacy_config();

    return privacy_attempt_store_update(static function (array &$data, int $now) use ($clientKey, $config): array {
        $entry = is_array($data[$clientKey] ?? null) ? $data[$clientKey] : [];
        $lockedUntil = (int) ($entry['locked_until'] ?? 0);
        if ($lockedUntil > $now) {
            return [
                'locked' => true,
                'locked_until' => $lockedUntil,
                'remaining_attempts' => 0,
            ];
        }

        $failures = (int) ($entry['failures'] ?? 0) + 1;
        $lockedUntil = 0;
        if ($failures >= $config['max_attempts']) {
            $failures = $config['max_attempts'];
            $lockedUntil = $now + $config['lockout_seconds'];
        }

        $data[$clientKey] = [
            'failures' => $failures,
            'locked_until' => $lockedUntil,
            'last_failure' => $now,
        ];

        return [
            'locked' => $lockedUntil > $now,
            'locked_until' => $lockedUntil,
            'remaining_attempts' => max(0, $config['max_attempts'] - $failures),
        ];
    });
}

function privacy_clear_failures(): void
{
    $clientKey = privacy_client_key();
    privacy_attempt_store_update(static function (array &$data, int $now) use ($clientKey): bool {
        unset($data[$clientKey]);
        return true;
    });
}

function privacy_verify_pin(string $pin): bool
{
    if (!preg_match('/^\d{4}$/', $pin)) {
        return false;
    }

    return privacy_is_configured() && password_verify($pin, privacy_config()['pin_hash']);
}

function ensure_directory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!@mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Impossibile creare la cartella richiesta: ' . $path);
    }
}

function app_upload_max_bytes(): int
{
    $uploadMaxSizeMb = (int) app_setting('upload_max_size_mb', 10);

    return max(1, $uploadMaxSizeMb) * 1024 * 1024;
}

function uploaded_xlsx_looks_valid(string $path): bool
{
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }

    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return false;
    }

    $signature = fread($handle, 4);
    fclose($handle);

    if (!is_string($signature) || substr($signature, 0, 2) !== 'PK') {
        return false;
    }

    if (!class_exists('ZipArchive')) {
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return false;
    }

    $hasWorkbook = $zip->locateName('xl/workbook.xml', ZipArchive::FL_NOCASE) !== false;
    $hasContentTypes = $zip->locateName('[Content_Types].xml', ZipArchive::FL_NOCASE) !== false;
    $zip->close();

    return $hasWorkbook && $hasContentTypes;
}

function uploaded_spreadsheet_type(string $path, string $originalFileName): ?string
{
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $extension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
    if (!in_array($extension, ['xls', 'xlsx', 'csv'], true)) {
        return null;
    }

    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return null;
    }

    $signature = fread($handle, 8);
    fclose($handle);

    if (!is_string($signature)) {
        return null;
    }

    if ($extension === 'csv') {
        $sample = file_get_contents($path, false, null, 0, 16384);
        if (!is_string($sample) || trim($sample) === '' || str_contains($sample, "\0")) {
            return null;
        }
        return 'csv';
    }

    $hasExpectedSignature = $extension === 'xlsx'
        ? substr($signature, 0, 2) === 'PK'
        : $signature === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    if (!$hasExpectedSignature) {
        return null;
    }

    if ($extension === 'xlsx' && !uploaded_xlsx_looks_valid($path)) {
        return null;
    }

    if (class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
        try {
            $identifiedType = \PhpOffice\PhpSpreadsheet\IOFactory::identify($path);
        } catch (Throwable $e) {
            return null;
        }

        $expectedType = $extension === 'xls' ? 'Xls' : 'Xlsx';
        if ($identifiedType !== $expectedType) {
            return null;
        }
    } elseif ($extension === 'xls') {
        return null;
    }

    return $extension;
}

function normalize_ascii(string $value): string
{
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    if (class_exists('Transliterator')) {
        $converted = transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC; Latin-ASCII', $value);
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }
    }

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }
    }

    return $value;
}

function parse_decimal_nullable($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_int($value) || is_float($value)) {
        $number = (float) $value;
        return is_finite($number) ? $number : null;
    }

    if (is_numeric($value)) {
        $number = (float) $value;
        return is_finite($number) ? $number : null;
    }

    $normalized = trim((string) $value);
    $normalized = str_replace(["\u{00A0}", ' ', '€', '%'], '', $normalized);

    $lastComma = strrpos($normalized, ',');
    $lastDot = strrpos($normalized, '.');

    if ($lastComma !== false && $lastDot !== false) {
        if ($lastComma > $lastDot) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } else {
            $normalized = str_replace(',', '', $normalized);
        }
    } elseif ($lastComma !== false) {
        $normalized = str_replace(',', '.', $normalized);
    }

    if (!is_numeric($normalized)) {
        return null;
    }

    $number = (float) $normalized;
    return is_finite($number) ? $number : null;
}

function parse_decimal($value): float
{
    return parse_decimal_nullable($value) ?? 0.0;
}

function parse_percentage_to_decimal($value): float
{
    $num = parse_decimal($value);
    if (abs($num) > 1) {
        return $num / 100;
    }

    return $num;
}
