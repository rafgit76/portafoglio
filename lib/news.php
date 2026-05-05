<?php

function build_portfolio_keywords(array $assets): array
{
    $keywords = [];
    $stopwords = [
        'THE', 'AND', 'INC', 'ETF', 'BTP', 'VAL', 'CUM', 'USD', 'EUR', 'ALL', 'WORLD', 'UCITS', 'FUND', 'FUN', 'PAC', 'VALORE', 'ITALIACUM'
    ];

    foreach ($assets as $a) {
        $ticker = strtoupper(trim((string) ($a['ticker'] ?? '')));
        if ($ticker !== '' && $ticker !== '-') {
            $tickerClean = preg_replace('/[^A-Z0-9]/', ' ', $ticker);
            foreach (preg_split('/\s+/', $tickerClean) as $tk) {
                if ($tk !== '' && strlen($tk) >= 2) {
                    $keywords[$tk] = true;
                }
            }
        }

        $name = strtoupper((string) ($a['name'] ?? ''));
        $name = preg_replace('/[^A-Z0-9 ]/', ' ', $name);
        foreach (preg_split('/\s+/', $name) as $part) {
            $part = trim($part);
            if ($part === '' || strlen($part) < 3 || in_array($part, $stopwords, true)) {
                continue;
            }
            $keywords[$part] = true;
        }
    }

    return array_keys($keywords);
}

function get_dashboard_news(array $portfolioKeywords, int $ttlSeconds = 900): array
{
    $cachePath = __DIR__ . '/../storage/news_cache.json';
    $feedVersion = 'v4_freshness_hitech_fallback';
    $kwHash = substr(sha1($feedVersion . '|' . implode('|', $portfolioKeywords)), 0, 16);

    if (is_file($cachePath)) {
        $cached = json_decode((string) file_get_contents($cachePath), true);
        if (is_array($cached)
            && isset($cached['fetched_at'], $cached['hash'], $cached['data'])
            && (time() - (int) $cached['fetched_at']) < $ttlSeconds
            && $cached['hash'] === $kwHash
        ) {
            return [
                'data' => $cached['data'],
                'from_cache' => true,
                'error' => null,
            ];
        }
    }

    $feeds = [
        'Finanza' => [
            'https://news.google.com/rss/search?q=finanza+mercati+borsa+obbligazioni+when:2d&hl=it&gl=IT&ceid=IT:it',
            'https://news.google.com/rss/search?q=eur+usd+mercati+finanziari+when:2d&hl=it&gl=IT&ceid=IT:it',
        ],
        'Politica' => [
            'https://news.google.com/rss/search?q=politica+economica+europa+italia+when:2d&hl=it&gl=IT&ceid=IT:it',
        ],
        'Intelligenza Artificiale' => [
            'https://news.google.com/rss/search?q=intelligenza+artificiale+nvidia+microsoft+when:2d&hl=it&gl=IT&ceid=IT:it',
            'https://news.google.com/rss/search?q=ai+chip+semiconduttori+when:2d&hl=it&gl=IT&ceid=IT:it',
        ],
        'Hi-Tech' => [
            'https://news.google.com/rss/search?q=tecnologia+software+cloud+semiconduttori+when:2d&hl=it&gl=IT&ceid=IT:it',
            'https://news.google.com/rss/search?q=high+tech+innovation+semiconductors+when:7d&hl=it&gl=IT&ceid=IT:it',
            'https://news.google.com/rss/search?q=nvidia+microsoft+chip+cloud+when:7d&hl=it&gl=IT&ceid=IT:it',
        ],
    ];

    $out = [];
    $anyError = false;
    $maxAgeHours = 72;
    $fallbackAgeHours = 240;

    foreach ($feeds as $category => $urls) {
        $items = [];
        foreach ($urls as $url) {
            $timeout = (int) app_setting('external_fetch_timeout_seconds', 3);
            $xmlBody = fetch_url_text_news($url, max(1, $timeout));
            if ($xmlBody === null) {
                $anyError = true;
                continue;
            }

            $parsed = parse_rss_items($xmlBody);
            foreach ($parsed as $it) {
                $key = sha1(($it['title'] ?? '') . '|' . ($it['link'] ?? ''));
                $items[$key] = $it;
            }
        }

        $items = array_values($items);

        $freshItems = array_values(array_filter($items, static function (array $it) use ($maxAgeHours): bool {
            if (empty($it['timestamp'])) {
                return false;
            }
            $ageHours = (time() - (int) $it['timestamp']) / 3600;
            return $ageHours <= $maxAgeHours;
        }));

        $fallbackItems = array_values(array_filter($items, static function (array $it) use ($fallbackAgeHours): bool {
            if (empty($it['timestamp'])) {
                return false;
            }
            $ageHours = (time() - (int) $it['timestamp']) / 3600;
            return $ageHours <= $fallbackAgeHours;
        }));

        $workItems = count($freshItems) >= 2 ? $freshItems : $fallbackItems;

        foreach ($workItems as &$item) {
            $score = 0.0;
            $hay = strtoupper(($item['title'] ?? '') . ' ' . ($item['description'] ?? ''));

            foreach ($portfolioKeywords as $kw) {
                if ($kw !== '' && strpos($hay, strtoupper($kw)) !== false) {
                    $score += 1.2;
                }
            }

            if (!empty($item['timestamp'])) {
                $ageHours = max(0, (time() - (int) $item['timestamp']) / 3600);

                if ($ageHours <= 6) {
                    $score += 6.0;
                } elseif ($ageHours <= 24) {
                    $score += 4.5;
                } elseif ($ageHours <= 48) {
                    $score += 3.0;
                } elseif ($ageHours <= 72) {
                    $score += 1.5;
                } else {
                    $score -= min(4.0, ($ageHours - 72) / 48);
                }
            }

            $item['score'] = $score;
        }
        unset($item);

        usort($workItems, static function ($a, $b) {
            $scoreCmp = ($b['score'] <=> $a['score']);
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }
            return ((int) ($b['timestamp'] ?? 0) <=> (int) ($a['timestamp'] ?? 0));
        });

        $out[$category] = array_slice($workItems, 0, 5);
    }

    $payload = [
        'fetched_at' => time(),
        'hash' => $kwHash,
        'data' => $out,
    ];

    $storageDir = dirname($cachePath);
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0775, true);
    }
    @file_put_contents($cachePath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return [
        'data' => $out,
        'from_cache' => false,
        'error' => $anyError ? 'partial_feed_error' : null,
    ];
}

function parse_rss_items(string $xmlBody): array
{
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlBody);
    if (!$xml) {
        return [];
    }

    $items = [];
    if (isset($xml->channel->item)) {
        foreach ($xml->channel->item as $it) {
            $title = trim((string) ($it->title ?? ''));
            $link = trim((string) ($it->link ?? ''));
            if ($title === '' || $link === '') {
                continue;
            }

            $pub = trim((string) ($it->pubDate ?? ''));
            $ts = $pub !== '' ? strtotime($pub) : null;

            $items[] = [
                'title' => $title,
                'link' => $link,
                'description' => trim((string) ($it->description ?? '')),
                'pub_date' => $pub,
                'timestamp' => $ts !== false ? $ts : null,
            ];
        }
    }

    return $items;
}

function fetch_url_text_news(string $url, int $timeoutSeconds = 8): ?string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
            'user_agent' => 'RaffiMacPortfolioNews/1.0',
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
            CURLOPT_USERAGENT => 'RaffiMacPortfolioNews/1.0',
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
