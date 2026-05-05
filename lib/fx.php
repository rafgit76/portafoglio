<?php

function get_eurusd_rate(int $ttlSeconds = 3600): array
{
    $cachePath = __DIR__ . '/../storage/eurusd_cache.json';

    if (is_file($cachePath)) {
        $cached = json_decode((string) file_get_contents($cachePath), true);
        if (is_array($cached) && isset($cached['fetched_at']) && (time() - (int) $cached['fetched_at']) < $ttlSeconds) {
            return [
                'rate' => isset($cached['rate']) ? (float) $cached['rate'] : null,
                'as_of' => $cached['as_of'] ?? null,
                'source' => 'ECB',
                'from_cache' => true,
                'error' => null,
            ];
        }
    }

    $timeout = (int) app_setting('external_fetch_timeout_seconds', 3);
    $url = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';
    $xmlBody = fetch_url_text($url, max(1, $timeout));

    if ($xmlBody === null) {
        if (is_file($cachePath)) {
            $cached = json_decode((string) file_get_contents($cachePath), true);
            if (is_array($cached) && isset($cached['rate'])) {
                return [
                    'rate' => (float) $cached['rate'],
                    'as_of' => $cached['as_of'] ?? null,
                    'source' => 'ECB',
                    'from_cache' => true,
                    'error' => 'feed_unreachable',
                ];
            }
        }

        return [
            'rate' => null,
            'as_of' => null,
            'source' => 'ECB',
            'from_cache' => false,
            'error' => 'feed_unreachable',
        ];
    }

    $parsed = parse_ecb_eurusd($xmlBody);
    if ($parsed['rate'] === null) {
        return [
            'rate' => null,
            'as_of' => null,
            'source' => 'ECB',
            'from_cache' => false,
            'error' => 'invalid_feed',
        ];
    }

    $payload = [
        'rate' => $parsed['rate'],
        'as_of' => $parsed['as_of'],
        'fetched_at' => time(),
    ];

    $storageDir = dirname($cachePath);
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0775, true);
    }
    @file_put_contents($cachePath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return [
        'rate' => (float) $parsed['rate'],
        'as_of' => $parsed['as_of'],
        'source' => 'ECB',
        'from_cache' => false,
        'error' => null,
    ];
}

function parse_ecb_eurusd(string $xmlBody): array
{
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlBody);
    if (!$xml) {
        return ['rate' => null, 'as_of' => null];
    }

    $timeNodes = $xml->xpath("//*[local-name()='Cube' and @time]");
    if (!$timeNodes || !isset($timeNodes[0])) {
        return ['rate' => null, 'as_of' => null];
    }

    $dayNode = $timeNodes[0];
    $asOf = (string) $dayNode['time'];

    $rate = null;
    foreach ($dayNode->children() as $child) {
        $attrs = $child->attributes();
        if (((string) ($attrs['currency'] ?? '')) === 'USD') {
            $r = (string) ($attrs['rate'] ?? '');
            if (is_numeric($r)) {
                $rate = (float) $r;
            }
            break;
        }
    }

    return ['rate' => $rate, 'as_of' => $asOf !== '' ? $asOf : null];
}

function fetch_url_text(string $url, int $timeoutSeconds = 8): ?string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
            'user_agent' => 'RaffiMacPortfolio/1.0',
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $content = @file_get_contents($url, false, $context);
    if ($content !== false && $content !== '') {
        return $content;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'RaffiMacPortfolio/1.0',
        ]);
        $resp = curl_exec($ch);
        $ok = !curl_errno($ch) && is_string($resp) && $resp !== '';
        curl_close($ch);

        if ($ok) {
            return $resp;
        }
    }

    return null;
}
