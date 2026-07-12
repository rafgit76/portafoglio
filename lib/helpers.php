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
    if (!in_array($extension, ['xls', 'xlsx'], true)) {
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
