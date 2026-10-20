<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

const BASE_URL  = 'https://watchanimeworld.one/';
const SITE_HOST = 'watchanimeworld.one';
const CACHE_TTL = 21600;
define('CACHE_DIR', __DIR__ . '/.anistream_cache');


$action = $_GET['action'] ?? '';
if ($action === 'debug')        { handleDebug();        exit; }
if ($action === 'debugplayer')  { handlePlayerDebug();  exit; }
if ($action === 'debugembed')   { handleEmbedDebug();   exit; }
if ($action === 'debugservers') { handleServersDebug(); exit; }
if ($action === 'debugzeph')    { handleZephDebug();    exit; }
if ($action === 'debugseasons') { handleDebugSeasons(); exit; }
if ($action === 'debugm3u8')    { handleM3u8Debug();    exit; }
if ($action === 'probe')        { handleProbe();        exit; }
if ($action === 'episoderaw')   { handleEpisodeRaw();   exit; }
if ($action === 'clear')        { handleClear();        exit; }
if ($action === 'img')          { handleImg();          exit; }
if ($action === 'stream')       { handleStream();       exit; }
if ($action === 'servers')      { handleServers();      exit; }
if ($action === 'player')       { handlePlayer();       exit; }
if ($action === 'home')         { handleApiHome();      exit; }
if ($action === 'search')       { handleApiSearch();    exit; }
if ($action === 'details')      { handleApiDetails();   exit; }
renderUi();
exit;

/* ============================================================
 *  HELPERS
 * ============================================================ */

function isBadImageUrl(string $url): bool {
    $u = strtolower($url);
    $bad = ['crunchyroll','netflix','disney','hulu','funimation','prime-video','amazon','hbo',
            'logo','icon','avatar','placeholder','sprite','banner-','-banner','adsystem','ads-',
            '-ad.','/ad/','/ads/','pixel','1x1','blank','spacer','tracker','analytics',
            'doubleclick','googleads','facebook.com','twitter.com','instagram','ytimg','youtube'];
    foreach ($bad as $b) if (strpos($u, $b) !== false) return true;
    if (!preg_match('#\.(jpg|jpeg|png|webp|gif|avif)(\?|$)#i', $u) && strpos($u, 'tmdb.org') === false) return true;
    return false;
}

function currentScriptUrl(string $querySuffix = ''): string {
    $scheme = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') $scheme = 'https';
    elseif (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') $scheme = 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = $_SERVER['SCRIPT_NAME'] ?? ('/' . basename(__FILE__));
    return $scheme . '://' . $host . $path . $querySuffix;
}

function fetchUrl(string $url, int $timeout = 25, string $referer = '', array $extraHeaders = []): ?string {
    $ch = curl_init($url);
    $headers = array_merge([
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
    ], $extraHeaders);
    if ($referer !== '') $headers[] = 'Referer: ' . $referer;
    else                 $headers[] = 'Referer: ' . BASE_URL;

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 300 && $body !== false) ? (string)$body : null;
}

function ajaxPost(array $params, int $timeout = 20): ?string {
    $ch = curl_init(BASE_URL . 'wp-admin/admin-ajax.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_TIMEOUT        => $timeout, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json, text/javascript, */*; q=0.01',
            'X-Requested-With: XMLHttpRequest',
            'Referer: ' . BASE_URL
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 300 && $body !== false) ? (string)$body : null;
}

function cacheFile(string $key): string {
    return CACHE_DIR . '/' . preg_replace('/[^a-z0-9_\-]/i', '_', $key) . '.json';
}
function cacheGet(string $key, int $ttl = CACHE_TTL, bool $allowStale = false): ?string {
    $f = cacheFile($key);
    if (!is_file($f)) return null;
    if (!$allowStale && time() - filemtime($f) > $ttl) return null;
    $d = @file_get_contents($f);
    return $d === false ? null : $d;
}
function cachePut(string $key, string $data): void {
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0755, true);
    $f = cacheFile($key);
    $tmp = $f . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $data) !== false) @rename($tmp, $f);   // atomic write
    cacheMaybeCleanup();
}
// Single-flight lock: if many users miss the same key at once, only one hits the source site.
function cacheLock(string $key) {
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0755, true);
    $h = @fopen(CACHE_DIR . '/' . md5($key) . '.lock', 'c');
    if ($h) @flock($h, LOCK_EX);
    return $h;
}
function cacheUnlock($h): void {
    if ($h) { @flock($h, LOCK_UN); @fclose($h); }
}
// Browser/CDN caching for successful API responses.
function apiCacheHeaders(int $seconds = 300): void {
    header('Cache-Control: public, max-age=' . $seconds . ', stale-while-revalidate=600');
}
// ~1 in 200 writes: delete old cache files so the folder never grows forever.
function cacheMaybeCleanup(): void {
    if (mt_rand(1, 200) !== 1) return;
    $now = time();
    foreach (glob(CACHE_DIR . '/*.{json,lock,tmp}', GLOB_BRACE) ?: [] as $f) if ($now - @filemtime($f) > 86400 * 2) @unlink($f);
    foreach (glob(CACHE_DIR . '/img/*') ?: [] as $f) if ($now - @filemtime($f) > 86400 * 14) @unlink($f);
}

function handleClear(): void {
    header('Content-Type: application/json');
    $what = $_GET['what'] ?? 'all';
    if ($what === 'details' || $what === 'all') {
        foreach (glob(CACHE_DIR . '/details_*.json') ?: [] as $f) @unlink($f);
        foreach (glob(CACHE_DIR . '/season_*.json')  ?: [] as $f) @unlink($f);
        foreach (glob(CACHE_DIR . '/servers_*.json') ?: [] as $f) @unlink($f);
        foreach (glob(CACHE_DIR . '/zeph_*.json')    ?: [] as $f) @unlink($f);
        foreach (glob(CACHE_DIR . '/cookies_*.txt')  ?: [] as $f) @unlink($f);
    }
    if ($what === 'all') {
        foreach (glob(CACHE_DIR . '/*.json') ?: [] as $f) @unlink($f);
        foreach (glob(CACHE_DIR . '/*.txt')  ?: [] as $f) @unlink($f);
    }
    echo '{"ok":true}';
}

function parseHtml(string $html): DOMDocument {
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $doc;
}

function absoluteUrl(string $base, string $href): string {
    if ($href === '') return '';
    if (preg_match('#^https?://#i', $href)) return $href;
    if (strpos($href, '//') === 0) return (strpos($base, 'https') === 0 ? 'https:' : 'http:') . $href;
    $p = parse_url($base);
    if (!$p || empty($p['host'])) return $href;
    $scheme = $p['scheme'] ?? 'https';
    $host   = $p['host'];
    if ($href[0] === '/') return "$scheme://$host$href";
    $path = $p['path'] ?? '/';
    $dir  = substr($path, 0, (int)strrpos($path, '/') + 1);
    return "$scheme://$host$dir$href";
}

function findClosestLink(DOMXPath $xp, DOMNode $img, string $baseUrl): string {
    $node = $img->parentNode;
    $depth = 0;
    while ($node && $depth < 6) {
        if ($node->nodeName === 'a' && $node instanceof DOMElement) {
            $href = $node->getAttribute('href');
            if ($href !== '') return absoluteUrl($baseUrl, $href);
        }
        $found = $xp->query('.//a[contains(@href, "/series/") or contains(@href, "/movies/")]', $node);
        if ($found && $found->length > 0) {
            $a = $found->item(0);
            if ($a instanceof DOMElement) return absoluteUrl($baseUrl, $a->getAttribute('href'));
        }
        $node = $node->parentNode;
        $depth++;
    }
    return '';
}

function normalizeEmbed(string $embed, string $baseUrl): string {
    $embed = trim($embed);
    if ($embed === '') return '';
    if (strpos($embed, '//') === 0) return 'https:' . $embed;
    if (preg_match('#^https?://#i', $embed)) return $embed;
    return absoluteUrl($baseUrl, $embed);
}

function isBadEmbed(string $url): bool {
    $low = strtolower($url);
    if ($low === '' || $low === 'about:blank') return true;
    if (strpos($low, 'javascript:') === 0) return true;
    foreach (['google','doubleclick','adsystem','facebook','disqus','twitter','analytics','googletag','recaptcha'] as $b) {
        if (strpos($low, $b) !== false) return true;
    }
    return false;
}

function isZephyrixUrl(string $url): bool {
    $low = strtolower($url);
    return (strpos($low, 'zephyrix.org') !== false || strpos($low, 'zephyrflick.top') !== false);
}

/* ============================================================
 *  ZEPHYRIX EXTRACTOR
 * ============================================================ */

function extractZephyrixVideo(string $embedUrl, string $referer, int $depth = 0): string {
    if ($depth > 2) return '';
    if (!preg_match('#/video/([a-f0-9]+)#i', $embedUrl, $m)) return '';
    $hash = $m[1];
    $host = parse_url($embedUrl, PHP_URL_HOST) ?: 'play.zephyrix.org';
    $mainUrl = 'https://' . $host;
    $cookieFile = sys_get_temp_dir() . '/zeph_' . md5($hash) . '.txt';
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122.0.0.0 Safari/537.36';

    $playerHtml = '';
    $ch = curl_init($embedUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_COOKIEJAR => $cookieFile, CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_HTTPHEADER => [
            'Referer: ' . $referer,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
        ],
    ]);
    $playerHtml = (string)curl_exec($ch);
    curl_close($ch);

    if ($playerHtml !== '') {
        $patterns = [
            '#["\']?(https?://[^"\'\s<>]+?\.m3u8[^"\'\s<>]*)["\']?#i',
            '#sources\s*[:=]\s*\[\s*\{[^}]*?file\s*:\s*["\']([^"\']+\.(?:m3u8|mp4)[^"\']*)["\']#is',
            '#(?:file|src|source|hls|video_url|playlist)\s*:\s*["\']([^"\']+?\.(?:m3u8|mp4)[^"\']*)["\']#i',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $playerHtml, $mm)) {
                $u = $mm[1];
                if (strpos($u, '//') === 0) $u = 'https:' . $u;
                @unlink($cookieFile);
                return $u;
            }
        }
        if (preg_match_all('#atob\(["\']([A-Za-z0-9+/=]+)["\']\)#', $playerHtml, $bm)) {
            foreach ($bm[1] as $b64) {
                $dec = @base64_decode($b64);
                if ($dec && preg_match('#\.(?:m3u8|mp4)#i', $dec)) {
                    if (strpos($dec, '//') === 0) $dec = 'https:' . $dec;
                    @unlink($cookieFile);
                    return $dec;
                }
            }
        }
        if (preg_match('#(https?:\\\\/\\\\/[^"\']+?\.(?:m3u8|mp4)[^"\']*)#i', $playerHtml, $mm)) {
            @unlink($cookieFile);
            return str_replace('\\/', '/', $mm[1]);
        }
    }

    $apiUrl = $mainUrl . '/player/index.php?data=' . $hash . '&do=getVideo';
    $postVariants = [
        'hash=' . urlencode($hash) . '&r=' . urlencode($mainUrl . '/'),
        'hash=' . urlencode($hash) . '&r=' . urlencode($referer),
        'hash=' . urlencode($hash),
        'data=' . urlencode($hash) . '&do=getVideo',
    ];

    foreach ($postVariants as $body) {
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_COOKIEJAR => $cookieFile, CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => [
                'Referer: ' . $embedUrl,
                'Origin: ' . $mainUrl,
                'X-Requested-With: XMLHttpRequest',
                'Accept: application/json, text/javascript, */*; q=0.01',
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
            ],
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300 && $resp && is_string($resp)) {
            $json = json_decode($resp, true);
            if (is_array($json)) {
                foreach (['videoSource','source','file','url','hls','m3u8','video_url','secure_link','src','link'] as $k) {
                    if (!empty($json[$k]) && is_string($json[$k])) {
                        $u = $json[$k];
                        if (strpos($u, '//') === 0) $u = 'https:' . $u;
                        @unlink($cookieFile);
                        return $u;
                    }
                }
                $found = searchArrayForVideoUrl($json);
                if ($found !== '') { @unlink($cookieFile); return $found; }
            }
            if (preg_match('#https?://[^"\'\s<>]+?\.(?:m3u8|mp4)[^"\'\s<>]*#', $resp, $mm)) {
                @unlink($cookieFile);
                return $mm[0];
            }
        }
    }

    @unlink($cookieFile);

    if ($playerHtml !== '' && preg_match('#<iframe[^>]+src=["\']([^"\']+)["\']#i', $playerHtml, $m2)) {
        $nested = trim($m2[1]);
        if (strpos($nested, '//') === 0) $nested = 'https:' . $nested;
        elseif (strpos($nested, 'http') !== 0) $nested = absoluteUrl($embedUrl, $nested);
        if ($nested !== $embedUrl) return extractZephyrixVideo($nested, $referer, $depth + 1);
    }
    return '';
}

function searchArrayForVideoUrl(array $arr): string {
    foreach ($arr as $value) {
        if (is_string($value) && preg_match('#\.(?:m3u8|mp4)#i', $value)) {
            if (strpos($value, '//') === 0) $value = 'https:' . $value;
            return $value;
        }
        if (is_array($value)) {
            $found = searchArrayForVideoUrl($value);
            if ($found !== '') return $found;
        }
    }
    return '';
}

function handleZephDebug(): void {
    header('Content-Type: text/plain; charset=utf-8');
    $url = $_GET['url'] ?? '';
    if ($url === '') { echo "Pass ?url=https://play.zephyrix.org/video/HASH"; return; }
    echo "Embed URL: $url\n\n";
    $found = extractZephyrixVideo($url, BASE_URL);
    echo $found !== '' ? "FOUND: $found\n" : "NOT FOUND\n";
}

function handleDebugSeasons(): void {
    header('Content-Type: text/plain; charset=utf-8');
    $url = $_GET['url'] ?? '';
    if ($url === '') { echo "Pass ?url=SERIES_URL"; return; }

    echo "Testing: $url\n";
    echo str_repeat("=", 60) . "\n\n";

    $html = fetchUrl($url, 25);
    if ($html === null) { echo "[X] FETCH FAILED\n"; return; }
    echo "[OK] Fetched " . number_format(strlen($html)) . " bytes\n\n";

    $nonce = '';
    if (preg_match('#torofilm_Public\s*=\s*\{[^}]*"nonce"\s*:\s*"([a-f0-9]+)"#i', $html, $m)) $nonce = $m[1];
    elseif (preg_match('#"nonce"\s*:\s*"([a-f0-9]{8,})"#i', $html, $m)) $nonce = $m[1];
    echo "Nonce: " . ($nonce ?: '[X] NOT FOUND') . "\n";

    $postId = ''; $seasonNums = [];
    if (preg_match_all('#data-post=["\'](\d+)["\'][^>]*data-season=["\'](\d+)["\']#i', $html, $pm, PREG_SET_ORDER)) {
        foreach ($pm as $x) { if ($postId === '') $postId = $x[1]; $seasonNums[(int)$x[2]] = true; }
    }
    ksort($seasonNums);
    $seasons = array_keys($seasonNums) ?: [1];
    echo "PostId: " . ($postId ?: '[X] NOT FOUND') . "\n";
    echo "Seasons in HTML: " . implode(', ', $seasons) . "\n\n";
    echo str_repeat("=", 60) . "\n\n";

    if ($nonce === '' || $postId === '') { echo "Cannot proceed.\n"; return; }

    foreach ($seasons as $sNum) {
        echo ">>> SEASON $sNum <<<\n";
        $t0 = microtime(true);
        $resp = ajaxPost([
            'action'=>'action_select_season',
            'post'=>$postId,
            'season'=>(string)$sNum,
            'nonce'=>$nonce,
        ], 20);
        $elapsed = round(microtime(true) - $t0, 2);

        if ($resp === null) {
            echo "  [X] NULL after {$elapsed}s (timeout/network)\n\n";
            continue;
        }
        echo "  [OK] OK in {$elapsed}s — " . number_format(strlen($resp)) . " bytes\n";
        echo "  Preview: " . htmlspecialchars(substr(trim($resp), 0, 120)) . "...\n";

        $eps = parseEpisodesFromAjaxHtml($resp, $sNum);
        echo "  Parsed: " . count($eps) . " episodes\n";
        foreach (array_slice($eps, 0, 3) as $e) echo "     S{$e['s']}E{$e['e']} → {$e['u']}\n";
        if (count($eps) > 3) echo "     ...+" . (count($eps)-3) . " more\n";
        echo "\n";
    }
    echo "DONE.\n";
}

function handleProbe(): void {
    header('Content-Type: text/html; charset=utf-8');
    $url = $_GET['url'] ?? '';
    $ref = $_GET['ref'] ?? 'https://play.zephyrix.org/';
    if ($url === '') { echo '<pre style="padding:20px;font-size:16px">Pass ?url=URL&ref=REFERER</pre>'; return; }
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<style>body{font-family:monospace;padding:12px;background:#0d0506;color:#f6eaea;font-size:13px;line-height:1.5}';
    echo '.box{background:#1a0a0c;padding:10px;border-radius:8px;margin:10px 0;border:1px solid #3a1a1f;overflow-x:auto;max-height:400px;overflow-y:auto}';
    echo 'pre{white-space:pre-wrap;word-break:break-all;margin:0;font-size:12px}';
    echo '.h{color:#b91c1c;font-weight:bold;font-size:15px;margin:20px 0 8px;padding:8px;background:#241014;border-radius:6px}';
    echo '.ok{color:#ef4444;background:#2a0a0a;padding:8px;border-radius:6px}';
    echo '.err{color:#f87171;background:#3a1a1a;padding:8px;border-radius:6px}';
    echo '</style></head><body>';
    echo '<h2 style="color:#b91c1c">Probe</h2>';
    echo '<div class="box"><b>URL:</b><br>' . htmlspecialchars($url) . '<br><br><b>Ref:</b><br>' . htmlspecialchars($ref) . '</div>';
    $headers = ['Accept: */*', 'Accept-Language: en-US,en;q=0.9'];
    $rHost = parse_url($ref, PHP_URL_HOST);
    $headers[] = 'Referer: ' . $ref;
    if ($rHost) $headers[] = 'Origin: https://' . $rHost;
    $upstreamHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => $headers, CURLOPT_ENCODING => '', CURLOPT_HEADER => false,
        CURLOPT_HEADERFUNCTION => function($ch, $h) use (&$upstreamHeaders) { $upstreamHeaders[] = trim($h); return strlen($h); },
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $effUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    echo '<div class="h">Response</div>';
    echo '<div class="box"><b>HTTP:</b> ' . $code . '<br><b>Content-Type:</b> ' . htmlspecialchars($ctype) . '<br><b>Final URL:</b> ' . htmlspecialchars($effUrl) . '<br><b>Body length:</b> ' . strlen((string)$body) . '</div>';
    if ($body !== false && is_string($body)) {
        $isM3u8 = (strncmp($body, '#EXTM3U', 7) === 0) || (strpos(substr($body,0,200), '#EXTM3U') !== false);
        echo '<div class="' . ($isM3u8 ? 'ok' : 'err') . '">' . ($isM3u8 ? '[OK] Looks like a playlist' : '[X] NOT a playlist') . '</div>';
    }
    echo '<div class="h">Upstream Headers</div>';
    echo '<div class="box"><pre>' . htmlspecialchars(implode("\n", $upstreamHeaders)) . '</pre></div>';
    echo '<div class="h">First 2000 chars</div>';
    echo '<div class="box"><pre>' . htmlspecialchars(substr((string)$body, 0, 2000)) . '</pre></div>';
    echo '</body></html>';
}

function handleM3u8Debug(): void {
    header('Content-Type: text/plain; charset=utf-8');
    $url = $_GET['url'] ?? '';
    $ref = $_GET['ref'] ?? 'https://play.zephyrix.org/';
    if ($url === '') { echo "Pass ?url=M3U8_URL&ref=REFERER"; return; }
    echo "URL: $url\nRef: $ref\n\n";
    $headers = ['Accept: */*', 'Accept-Language: en-US,en;q=0.9'];
    $rHost = parse_url($ref, PHP_URL_HOST);
    $headers[] = 'Referer: ' . $ref;
    if ($rHost) $headers[] = 'Origin: https://' . $rHost;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => $headers, CURLOPT_ENCODING => '',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    echo "HTTP Code: $code\nContent-Type: $ctype\nBody length: " . strlen((string)$body) . "\n\n--- First 3000 chars ---\n";
    echo substr((string)$body, 0, 3000);
}

/* ============================================================
 *  SCRAPERS
 * ============================================================ */

function scrapeGrid(string $url): array {
    $html = fetchUrl($url);
    if ($html === null) return ['items' => [], 'popular' => []];
    $doc = parseHtml($html);
    $xp  = new DOMXPath($doc);
    $popularNode = null;
    foreach ($xp->query('//h1|//h2|//h3|//h4') as $h) {
        $t = strtolower(trim($h->textContent));
        if (strpos($t, 'most watch') !== false || strpos($t, 'popular') !== false || strpos($t, 'trending') !== false) {
            $popularNode = $h->parentNode; break;
        }
    }
    $items = []; $popular = []; $seen = [];
    $imgs = $xp->query('//img[starts-with(@alt, "Image ")]');
    if (!$imgs || $imgs->length === 0)
        $imgs = $xp->query('//a[contains(@href,"/series/") or contains(@href,"/movies/")]//img');
    foreach ($imgs as $img) {
        if (!($img instanceof DOMElement)) continue;
        $alt   = trim($img->getAttribute('alt'));
        $title = stripos($alt, 'Image ') === 0 ? trim(substr($alt, 6)) : $alt;
        if ($title === '') continue;
        $title = preg_replace('/\s+/', ' ', $title);
        $src   = $img->getAttribute('src') ?: $img->getAttribute('data-src') ?: $img->getAttribute('data-lazy-src');
        if ($src === '') continue;
        $src = absoluteUrl($url, $src);
        if (isBadImageUrl($src)) continue;
        $link = findClosestLink($xp, $img, $url);
        if ($link === '') continue;
        $dedupeKey = strtolower(trim($title));
        if ($dedupeKey === '' || isset($seen[$dedupeKey])) continue;
        $seen[$dedupeKey] = true;
        $box = $img->parentNode; $depth = 0;
        while ($box && $depth < 5 && $box->nodeName !== 'article') { $box = $box->parentNode; $depth++; }
        if (!$box) $box = $img->parentNode;
        $text   = $box ? $box->textContent : '';
        $detail = '';
        if (preg_match('/Season\s*\d+/i', $text, $m))         $detail = $m[0];
        if (preg_match('/EP\s*:?\s*\d+[\-–]\d+/i', $text, $m2)) $detail = $detail ? ($detail . ' • ' . $m2[0]) : $m2[0];
        $entry = ['t' => $title, 'i' => $src, 'l' => $link, 'd' => $detail];
        $isPopular = false;
        if ($popularNode) { $n = $img; while ($n) { if ($n === $popularNode) { $isPopular = true; break; } $n = $n->parentNode; } }
        if ($isPopular) $popular[] = $entry; else $items[] = $entry;
    }
    return ['items' => $items, 'popular' => $popular];
}

function scrapeDetailPage(string $url): array {
    @set_time_limit(90);
    $html = fetchUrl($url, 20);
    if ($html === null) return ['title'=>'','poster'=>'','smallDetail'=>'','episodes'=>[],'_failed'=>true];
    $doc = parseHtml($html);
    $xp  = new DOMXPath($doc);
    $title = '';
    foreach (['//meta[@property="og:title"]/@content','//meta[@name="twitter:title"]/@content','//h1','//title'] as $q) {
        $t = $xp->query($q);
        if ($t && $t->length) { $c = trim($t->item(0)->nodeValue); if ($c !== '') { $title = $c; break; } }
    }
    $title = preg_replace('/\s*[-–—|]\s*(Watch|Stream|Download|Free|Anime).*$/i', '', $title);
    $title = preg_replace('/\s+in\s+(Hindi|Tamil|Telugu|English|Malayalam|Bengali|Kannada).*$/i', '', $title);
    $title = trim($title, " -–—|");
    $poster = ''; $candidates = [];
    foreach (['//meta[@property="og:image"]/@content','//meta[@name="twitter:image"]/@content','//link[@rel="image_src"]/@href','//img[contains(@class,"wp-post-image")]/@src'] as $q) {
        $p = $xp->query($q);
        if ($p && $p->length) { $c = trim($p->item(0)->nodeValue); if ($c !== '') $candidates[] = $c; }
    }
    foreach ($candidates as $c) { $full = absoluteUrl($url, $c); if (!isBadImageUrl($full)) { $poster = $full; break; } }
    if ($poster === '' && !empty($candidates)) $poster = absoluteUrl($url, $candidates[0]);
    $isMovie = (strpos($url, '/movies/') !== false) || (strpos($url, '/movie/') !== false);
    $nonce = '';
    if (preg_match('#torofilm_Public\s*=\s*\{[^}]*"nonce"\s*:\s*"([a-f0-9]+)"#i', $html, $nm)) $nonce = $nm[1];
    if ($nonce === '' && preg_match('#"nonce"\s*:\s*"([a-f0-9]{8,})"#i', $html, $nm)) $nonce = $nm[1];
    $postId = ''; $seasonNumbers = [];
    if (preg_match_all('#data-post=["\'](\d+)["\'][^>]*data-season=["\'](\d+)["\']#i', $html, $pm, PREG_SET_ORDER)) {
        foreach ($pm as $m) { if ($postId === '') $postId = $m[1]; $seasonNumbers[(int)$m[2]] = true; }
    }
    if ($postId === '' && preg_match('#data-post=["\'](\d+)["\']#i', $html, $pm2)) $postId = $pm2[1];
    if ($postId === '' && preg_match('#postid-(\d+)#i', $html, $pm3)) $postId = $pm3[1];
    ksort($seasonNumbers);
    $seasonNums = array_keys($seasonNumbers);
    if (empty($seasonNums)) $seasonNums = [1];
    $allEpisodes = [];
    if ($nonce !== '' && $postId !== '') {
        foreach ($seasonNums as $sNum) {
            $ck     = 'season_v5_' . $postId . '_' . $sNum;
            $cached = cacheGet($ck);
            if ($cached !== null) {
                $eps = json_decode($cached, true);
                if (is_array($eps) && !empty($eps)) { foreach ($eps as $e) $allEpisodes[] = $e; continue; }
            }
            $resp = ajaxPost([
                'action'=>'action_select_season',
                'post'=>$postId,
                'season'=>(string)$sNum,
                'nonce'=>$nonce,
            ], 20);
            if ($resp === null) continue;
            $data = $resp; $trimmed = trim($resp);
            if (isset($trimmed[0]) && $trimmed[0] === '{') {
                $j = json_decode($trimmed, true);
                if (is_array($j)) foreach (['data','html','content','episodes','result'] as $k)
                    if (isset($j[$k]) && is_string($j[$k])) { $data = $j[$k]; break; }
            }
            $eps = parseEpisodesFromAjaxHtml($data, $sNum);
            if (!empty($eps)) {
                cachePut($ck, json_encode($eps));
                foreach ($eps as $e) $allEpisodes[] = $e;
            }
        }
    }
    if (empty($allEpisodes)) {
        $staticEps = extractEpisodesFromHtml($html, $url, 0);
        foreach ($staticEps as $e) $allEpisodes[] = $e;
    }
    if (empty($allEpisodes) && $isMovie) $allEpisodes[] = ['s'=>1,'e'=>1,'u'=>$url,'t'=>'Full Movie','_movie'=>true];
    $bySE = [];
    foreach ($allEpisodes as $e) {
        $k = $e['s'].'_'.$e['e'];
        if (!isset($bySE[$k])) $bySE[$k] = $e;
    }
    return ['title'=>$title,'poster'=>$poster,'smallDetail'=>'','episodes'=>array_values($bySE),'_failed'=>false,'_isMovie'=>$isMovie];
}

function parseEpisodesFromAjaxHtml(string $html, int $season): array {
    $episodes = []; $seen = [];
    if ($html === '') return $episodes;

    // Block-wise parsing (safer for AJAX fragments)
    if (preg_match_all('#<li\b[^>]*>(.*?)</li>#is', $html, $blocks)) {
        foreach ($blocks[1] as $block) {
            if (!preg_match('#href=["\']([^"\']*?/episode/[^"\']+)["\']#i', $block, $hm)) continue;
            $href = trim($hm[1]);
            if ($href === '' || stripos($href, 'javascript:') === 0) continue;
            $href = absoluteUrl(BASE_URL, $href);
            if (isset($seen[$href])) continue;

            $sNum = $season; $epNum = null;

            if (preg_match('#num-epi[^>]*>\s*(\d+)x(\d+)#i', $block, $nm)) {
                $sNum = (int)$nm[1]; $epNum = (int)$nm[2];
            }
            if ($epNum === null && preg_match('#[-/](\d+)x(\d+)/?(?:[?#]|$)#', $href, $mm)) {
                $sNum = (int)$mm[1]; $epNum = (int)$mm[2];
            }
            if ($epNum === null && preg_match('#(?:episode|ep)[-_]?(\d+)#i', $href, $mm)) {
                $epNum = (int)$mm[1];
            }
            if ($epNum === null) continue;

            $seen[$href] = true;
            $episodes[] = ['s'=>$sNum, 'e'=>$epNum, 'u'=>$href, 't'=>''];
        }
    }

    // Fallback: direct href scan
    if (empty($episodes) && preg_match_all('#href=["\']([^"\']*?/episode/[^"\']+)["\']#i', $html, $m)) {
        foreach (array_unique($m[1]) as $href) {
            $href = absoluteUrl(BASE_URL, trim($href));
            if (preg_match('#[-/](\d+)x(\d+)/?(?:[?#]|$)#', $href, $mm)) {
                if (!isset($seen[$href])) {
                    $seen[$href] = true;
                    $episodes[] = ['s'=>(int)$mm[1],'e'=>(int)$mm[2],'u'=>$href,'t'=>''];
                }
            }
        }
    }

    return $episodes;
}

function extractEpisodesFromHtml(string $html, string $pageUrl, int $overrideSeason = 0): array {
    $doc = parseHtml($html); $xp = new DOMXPath($doc);
    $episodes = []; $seen = [];
    foreach ($xp->query('//a[contains(@href,"/episode/")]') as $a) {
        if (!($a instanceof DOMElement)) continue;
        $href = $a->getAttribute('href');
        if ($href === '' || stripos($href, 'javascript:') === 0) continue;
        $href = absoluteUrl($pageUrl, $href);
        if (isset($seen[$href])) continue;
        $text = trim($a->textContent);
        $season = null; $ep = null;
        if      (preg_match('#[-/](\d+)x(\d+)/?(?:[?#]|$)#', $href, $m))      { $season = (int)$m[1]; $ep = (int)$m[2]; }
        elseif  (preg_match('#(?:episode|ep)[-_]?(\d+)#i', $href, $m))          { $ep = (int)$m[1]; }
        elseif  (preg_match('/\b(?:episode|ep)\s*(\d+)/i', $text, $m))          { $ep = (int)$m[1]; }
        if ($ep === null) continue;
        if ($season === null) $season = $overrideSeason > 0 ? $overrideSeason : 1;
        $seen[$href] = true;
        $episodes[] = ['s'=>$season,'e'=>$ep,'u'=>$href,'t'=>''];
    }
    return $episodes;
}

function scrapeServers(string $url): array {
    $html = fetchUrl($url, 25);
    if ($html === null) return [];
    $doc = parseHtml($html); $xp = new DOMXPath($doc);
    $servers = [];
    $iframes = $xp->query('//iframe');
    $i = 1;
    if ($iframes) {
        foreach ($iframes as $ifr) {
            if (!($ifr instanceof DOMElement)) continue;
            $src = '';
            foreach (['data-src','data-litespeed-src','data-lazy-src','src'] as $k) {
                if ($ifr->hasAttribute($k) && $ifr->getAttribute($k) !== '') { $src = $ifr->getAttribute($k); break; }
            }
            $src = trim($src);
            if ($src === '' || isBadEmbed($src)) continue;
            $label = isZephyrixUrl($src) ? 'Multi-Audio Player' : 'Server ' . $i;
            $embed = normalizeEmbed($src, $url);
            if ($embed !== '' && !isBadEmbed($embed)) { $servers[] = ['label' => $label, 'embed' => $embed, 'lang' => '']; $i++; }
        }
    }
    return $servers;
}

function handleServers(): void {
    @set_time_limit(60);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    $url = trim((string)($_GET['url'] ?? ''));
    if ($url === '' || strpos($url, SITE_HOST) === false) { echo '{"servers":[]}'; return; }
    $ck = 'servers_v4_' . md5($url);
    $cached = cacheGet($ck);
    if ($cached !== null) { apiCacheHeaders(600); echo $cached; return; }
    $lk = cacheLock($ck);
    $cached = cacheGet($ck);
    if ($cached !== null) { cacheUnlock($lk); apiCacheHeaders(600); echo $cached; return; }
    try { $servers = scrapeServers($url); } catch (Throwable $e) { $servers = []; }
    $out = json_encode(['servers' => $servers], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($out !== false && !empty($servers)) cachePut($ck, $out);
    cacheUnlock($lk);
    echo $out !== false ? $out : '{"servers":[]}';
}

function handleServersDebug(): void {
    header('Content-Type: text/plain; charset=utf-8');
    $url = $_GET['url'] ?? '';
    if ($url === '') { echo "Pass ?url=EPISODE_URL"; return; }
    echo "URL: $url\n\n";
    $servers = scrapeServers($url);
    echo "Found " . count($servers) . " servers:\n\n";
    foreach ($servers as $i => $s) echo "[$i] Label: {$s['label']}\n    Embed: {$s['embed']}\n\n";
}

function handleEpisodeRaw(): void {
    header('Content-Type: text/html; charset=utf-8');
    $url = $_GET['url'] ?? '';
    if ($url === '' || strpos($url, SITE_HOST) === false) { echo '<pre>Pass ?url=EPISODE_URL</pre>'; return; }
    $html = fetchUrl($url, 25);
    if ($html === null) { echo '<pre>FETCH FAILED</pre>'; return; }
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<style>body{font-family:monospace;padding:12px;background:#0d0506;color:#f6eaea;font-size:13px}';
    echo '.box{background:#1a0a0c;padding:10px;border-radius:8px;margin:8px 0;border:1px solid #3a1a1f;overflow-x:auto;max-height:400px;overflow-y:auto}';
    echo 'pre{white-space:pre-wrap;word-break:break-all;margin:0;font-size:12px;color:#c9b5b7}</style></head><body>';
    echo '<h2 style="color:#b91c1c">Episode Analysis</h2>';
    echo '<div class="box"><b>URL:</b> '.htmlspecialchars($url).'<br><b>Size:</b> '.number_format(strlen($html)).' bytes</div>';
    if (preg_match_all('#<iframe[^>]*>#i', $html, $im)) foreach ($im[0] as $t) echo '<div class="box"><pre>'.htmlspecialchars($t).'</pre></div>';
    echo '</body></html>';
}

/* ============================================================
 *  API HANDLERS
 * ============================================================ */

function toApiItem(array $it): array {
    return ['id'=>$it['l'],'title'=>$it['t'],'poster'=>$it['i'],'smallDetail'=>$it['d'] ?? ''];
}

function handleApiHome(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    $ttl = 1800;
    $cached = cacheGet('home_v2', $ttl);
    if ($cached !== null) { apiCacheHeaders(300); echo $cached; return; }
    $lk = cacheLock('home_v2');
    $cached = cacheGet('home_v2', $ttl);
    if ($cached !== null) { cacheUnlock($lk); apiCacheHeaders(300); echo $cached; return; }
    $r   = scrapeGrid(BASE_URL);
    $out = json_encode(['latest'=>array_map('toApiItem',$r['items']),'trending'=>array_map('toApiItem',$r['popular'])], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($out !== false && (!empty($r['items']) || !empty($r['popular']))) {
        cachePut('home_v2', $out); cacheUnlock($lk); apiCacheHeaders(300); echo $out; return;
    }
    $stale = cacheGet('home_v2', $ttl, true);   // source site failed -> serve old copy
    cacheUnlock($lk);
    echo $stale ?? '{"latest":[],"trending":[]}';
}

function handleApiSearch(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') { echo '{"results":[]}'; return; }
    $ck     = 'search_v2_' . md5(strtolower($q));
    $cached = cacheGet($ck);
    if ($cached !== null) { apiCacheHeaders(600); echo $cached; return; }
    $lk = cacheLock($ck);
    $cached = cacheGet($ck);
    if ($cached !== null) { cacheUnlock($lk); apiCacheHeaders(600); echo $cached; return; }
    $r   = scrapeGrid(BASE_URL . '?s=' . urlencode($q));
    $out = json_encode(['results'=>array_map('toApiItem',$r['items'])], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($out !== false && !empty($r['items'])) { cachePut($ck, $out); cacheUnlock($lk); apiCacheHeaders(600); echo $out; return; }
    cacheUnlock($lk);
    echo $out !== false ? $out : '{"results":[]}';
}

function handleApiDetails(): void {
    @set_time_limit(90);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    $id    = trim((string)($_GET['id'] ?? ''));
    $fresh = isset($_GET['fresh']) && $_GET['fresh'] === '1';
    if ($id === '' || strpos($id, SITE_HOST) === false) { echo '{"error":"invalid_id"}'; return; }
    $ck = 'details_v10_' . md5($id);
    if (!$fresh) { $cached = cacheGet($ck); if ($cached !== null) { apiCacheHeaders(600); echo $cached; return; } }
    $lk = cacheLock($ck);
    if (!$fresh) { $cached = cacheGet($ck); if ($cached !== null) { cacheUnlock($lk); apiCacheHeaders(600); echo $cached; return; } }
    try { $detail = scrapeDetailPage($id); }
    catch (Throwable $e) { $stale = cacheGet($ck, CACHE_TTL, true); cacheUnlock($lk); echo $stale ?? json_encode(['title'=>'','poster'=>'','smallDetail'=>'','seasons'=>[],'_partial'=>true]); return; }
    $bySeason = [];
    foreach ($detail['episodes'] as $ep) $bySeason[$ep['s']][] = $ep;
    ksort($bySeason);
    $seasons = [];
    foreach ($bySeason as $num => $list) {
        usort($list, fn($a,$b) => $a['e'] <=> $b['e']);
        $seasons[] = ['label'=>'Season '.$num,'number'=>$num,'episodes'=>array_map(fn($e)=>['number'=>$e['e'],'url'=>$e['u'],'title'=>$e['t']],$list)];
    }
    $isMovie = !empty($detail['_isMovie']);
    if (empty($seasons) && $detail['title'] !== '') $isMovie = true;
    $isComplete = !empty($seasons) || (!empty($detail['_isMovie']) && $detail['title'] !== '');
    $out = json_encode([
        'title'=>$detail['title'],'poster'=>$detail['poster'],'smallDetail'=>$detail['smallDetail'],
        'seasons'=>$seasons,'isMovie'=>$isMovie,'_partial'=>!$isComplete && $detail['title'] !== '',
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($out !== false && $isComplete) { cachePut($ck, $out); cacheUnlock($lk); apiCacheHeaders(600); echo $out; return; }
    $stale = $isComplete ? null : cacheGet($ck, CACHE_TTL, true);   // scrape came back incomplete -> prefer old good copy
    cacheUnlock($lk);
    echo $stale ?? ($out !== false ? $out : '{"title":"","poster":"","smallDetail":"","seasons":[],"_partial":true}');
}

function handleDebug(): void {
    header('Content-Type: text/plain; charset=utf-8');
    $testUrl = $_GET['url'] ?? 'https://watchanimeworld.one/series/arifureta-from-commonplace-to-worlds-strongest/';
    echo "URL: $testUrl\n\n";
    $d = scrapeDetailPage($testUrl);
    echo "Title  : {$d['title']}\nPoster : {$d['poster']}\n\n";
    echo "Episodes (" . count($d['episodes']) . "):\n";
    $cnt = [];
    foreach ($d['episodes'] as $e) $cnt[$e['s']] = ($cnt[$e['s']] ?? 0) + 1;
    foreach ($cnt as $s => $c) echo "  Season $s: $c episodes\n";
}

function handleImg(): void {
    $url = $_GET['url'] ?? '';
    if ($url === '' || !preg_match('#^https?://#i', $url)) { http_response_code(400); exit; }
    if (!is_dir(CACHE_DIR . '/img')) @mkdir(CACHE_DIR . '/img', 0755, true);
    $cacheFile = CACHE_DIR . '/img/' . md5($url);
    $mimeFile  = $cacheFile . '.mime';
    if (is_file($cacheFile) && is_file($mimeFile) && filesize($cacheFile) > 100) {
        header('Content-Type: ' . trim((string)@file_get_contents($mimeFile)));
        header('Cache-Control: public, max-age=604800');
        readfile($cacheFile);
        return;
    }
    $host     = parse_url($url, PHP_URL_HOST) ?: '';
    $referers = strpos($host, 'tmdb') !== false ? ['https://www.themoviedb.org/',''] : [BASE_URL,''];
    $uas      = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17.0 Safari/605.1.15',
    ];
    $body = null; $mime = 'image/jpeg';
    foreach ($referers as $ref) {
        foreach ($uas as $ua) {
            $headers = ['Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8'];
            if ($ref !== '') $headers[] = 'Referer: ' . $ref;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>4,
                CURLOPT_TIMEOUT=>15, CURLOPT_CONNECTTIMEOUT=>6,
                CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_SSL_VERIFYHOST=>false,
                CURLOPT_USERAGENT=>$ua, CURLOPT_HTTPHEADER=>$headers,
            ]);
            $b = curl_exec($ch);
            $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $m = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'image/jpeg';
            curl_close($ch);
            if ($c >= 200 && $c < 300 && $b !== false && strlen($b) > 100) { $body = (string)$b; $mime = $m; break 2; }
        }
    }
    if ($body !== null) {
        @file_put_contents($cacheFile, $body);
        @file_put_contents($mimeFile, $mime);
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=604800');
        echo $body;
        return;
    }
    if (strpos($host, 'tmdb') !== false) { header('Location: ' . $url, true, 302); exit; }
    http_response_code(302);
    header('Location: data:image/svg+xml;base64,' . base64_encode(
        '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="400"><rect width="100%" height="100%" fill="#241014"/><text x="50%" y="50%" fill="#b09194" font-size="16" text-anchor="middle" dy=".3em">No Image</text></svg>'));
    exit;
}

/* ============================================================
 *  STREAM PROXY
 * ============================================================ */

function handleStream(): void {
    $url = $_GET['url'] ?? '';
    $ref = $_GET['ref'] ?? '';
    if ($url === '' || !preg_match('#^https?://#i', $url)) { http_response_code(400); exit; }

    $headers = ['Accept: */*', 'Accept-Language: en-US,en;q=0.9'];
    if ($ref !== '') {
        $headers[] = 'Referer: ' . $ref;
        $rHost = parse_url($ref, PHP_URL_HOST);
        if ($rHost) $headers[] = 'Origin: https://' . $rHost;
    } else { $headers[] = 'Referer: ' . BASE_URL; }
    if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=/', $_SERVER['HTTP_RANGE'])) $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];

    $upstreamHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => $headers, CURLOPT_ENCODING => '', CURLOPT_HEADER => false,
        CURLOPT_HEADERFUNCTION => function($ch, $h) use (&$upstreamHeaders) { $upstreamHeaders[] = trim($h); return strlen($h); },
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $clen = (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($ch);

    if ($code < 200 || $code >= 300 || $body === false) {
        http_response_code($code ?: 502);
        header('Content-Type: text/plain');
        header('Access-Control-Allow-Origin: *');
        echo "Upstream error: HTTP $code";
        exit;
    }

    $bodyStr = (string)$body;
    $isM3u8 = false;
    if (stripos($url, '.m3u8') !== false) $isM3u8 = true;
    elseif (stripos($ctype, 'mpegurl') !== false) $isM3u8 = true;
    else {
        $head = substr($bodyStr, 0, 300);
        if (strpos($head, '#EXTM3U') !== false) $isM3u8 = true;
        elseif (strpos($head, '#EXT-X-STREAM-INF') !== false) $isM3u8 = true;
        elseif (strpos($head, '#EXT-X-TARGETDURATION') !== false) $isM3u8 = true;
        elseif (strpos($head, '#EXT-X-MEDIA-SEQUENCE') !== false) $isM3u8 = true;
        elseif (strpos($head, '#EXTINF:') !== false && strpos($head, '#EXT-X-') !== false) $isM3u8 = true;
    }

    if ($isM3u8) {
        header('Content-Type: application/vnd.apple.mpegurl');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        $proxyBase = currentScriptUrl('?action=stream&ref=' . urlencode($ref) . '&url=');
        $lines = preg_split('/\r?\n/', $bodyStr);
        $out = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') { $out[] = $line; continue; }
            if ($trimmed[0] === '#') {
                if (preg_match_all('/URI="([^"]+)"/i', $trimmed, $um, PREG_SET_ORDER)) {
                    foreach ($um as $entry) {
                        $origUri = $entry[1];
                        $abs = absoluteUrl($url, $origUri);
                        $newUrl = $proxyBase . urlencode($abs);
                        $trimmed = str_replace('URI="' . $origUri . '"', 'URI="' . $newUrl . '"', $trimmed);
                    }
                }
                $out[] = $trimmed; continue;
            }
            $abs = absoluteUrl($url, $trimmed);
            $out[] = $proxyBase . urlencode($abs);
        }
        echo implode("\n", $out);
        exit;
    }

    $outCtype = $ctype ?: 'video/mp2t';
    if (stripos($outCtype, 'octet-stream') !== false) $outCtype = 'video/mp2t';
    if (stripos($outCtype, 'text/plain') !== false) $outCtype = 'video/mp2t';
    header('Content-Type: ' . $outCtype);
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: public, max-age=3600');
    header('Accept-Ranges: bytes');
    if (!empty($_SERVER['HTTP_RANGE']) && $code === 206) {
        http_response_code(206);
        foreach ($upstreamHeaders as $h) { if (stripos($h, 'Content-Range:') === 0) header($h); }
    }
    if ($clen > 0) header('Content-Length: ' . $clen);
    echo $bodyStr;
    exit;
}

/* ============================================================
 *  EMBED HOST SCRAPER
 * ============================================================ */

function extractVideoFromEmbed(string $embedUrl, string $originalReferer, int $depth = 0): string {
    if ($depth > 3) return '';
    $html = fetchUrl($embedUrl, 15, $originalReferer);
    if ($html === null) $html = fetchUrl($embedUrl, 15, '');
    if ($html === null) return '';
    $patterns = [
        '#["\'](https?://[^"\']+?\.m3u8[^"\']*)["\']#i',
        '#["\'](https?://[^"\']+?\.mp4[^"\']*)["\']#i',
        '#(?:file|source|src|url|hls|video_url|playlist)\s*[:=]\s*["\']([^"\']+?\.(?:m3u8|mp4)[^"\']*)["\']#i',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $html, $m)) {
            $cand = $m[1];
            if (strpos($cand, '//') === 0) $cand = 'https:' . $cand;
            if (preg_match('#\.(?:m3u8|mp4)(\?|$)#i', $cand)) return $cand;
        }
    }
    if (preg_match('#<iframe[^>]+src=["\']([^"\']+)["\']#i', $html, $m)) {
        $nested = trim($m[1]);
        if (strpos($nested, '//') === 0) $nested = 'https:' . $nested;
        elseif (strpos($nested, 'http') !== 0) $nested = absoluteUrl($embedUrl, $nested);
        if ($nested !== $embedUrl && stripos($nested, 'google') === false) return extractVideoFromEmbed($nested, $originalReferer, $depth + 1);
    }
    return '';
}

/* ============================================================
 *  PLAYER
 * ============================================================ */

function handlePlayer(): void {
    $url = $_GET['url'] ?? '';
    if ($url === '' || strpos($url, SITE_HOST) === false) { http_response_code(400); exit('Invalid URL'); }

    $forceEmbed = trim((string)($_GET['embed'] ?? ''));
    if ($forceEmbed !== '' && strpos($forceEmbed, '//') === 0) $forceEmbed = 'https:' . $forceEmbed;

    $embedUrl = ''; $videoSrc = ''; $html = '';

    if ($forceEmbed !== '' && preg_match('#^https?://#i', $forceEmbed)) {
        $embedUrl = $forceEmbed;
        if (isZephyrixUrl($embedUrl)) {
            $vk = 'pvideo_' . md5($embedUrl . '|' . $url);
            $cv = cacheGet($vk, 900);
            if ($cv !== null && $cv !== '') { $videoSrc = $cv; }
            else {
                $real = extractZephyrixVideo($embedUrl, $url);
                if ($real !== '') { $videoSrc = $real; cachePut($vk, $real); }
            }
        }
    } else {
        $embedKey = 'pembed_' . md5($url);
        $cachedEmbed = cacheGet($embedKey, 21600);
        if ($cachedEmbed !== null && $cachedEmbed !== '') {
            $embedUrl = $cachedEmbed;
            $html = '';
        } else {
        $html = fetchUrl($url, 25);
        if ($html === null) { header('Location: ' . $url, true, 302); exit; }
        $iframePatterns = [
            '#<iframe[^>]+data-src=["\']([^"\']+)["\']#i',
            '#<iframe[^>]+src=["\']([^"\']+)["\']#i',
            '#<iframe[^>]+(?:data-litespeed-src|data-lazy-src|data-original)=["\']([^"\']+)["\']#i',
        ];
        foreach ($iframePatterns as $p) {
            if (preg_match($p, $html, $m)) {
                $c = trim($m[1]);
                if ($c === '' || stripos($c, 'javascript') === 0 || stripos($c, 'about:blank') === 0) continue;
                if (isBadEmbed($c)) continue;
                $embedUrl = $c; break;
            }
        }
        if ($embedUrl !== '' && strpos($embedUrl, '//') === 0) $embedUrl = 'https:' . $embedUrl;
        elseif ($embedUrl !== '' && strpos($embedUrl, 'http') !== 0) $embedUrl = absoluteUrl($url, $embedUrl);
        if ($embedUrl !== '') cachePut($embedKey, $embedUrl);
        }
        if ($embedUrl !== '' && $videoSrc === '' && isZephyrixUrl($embedUrl)) {
            $vk = 'pvideo_' . md5($embedUrl . '|' . $url);
            $cv = cacheGet($vk, 900);   // direct video links can expire -> only 15 min
            if ($cv !== null && $cv !== '') { $videoSrc = $cv; }
            else {
                $real = extractZephyrixVideo($embedUrl, $url);
                if ($real !== '') { $videoSrc = $real; cachePut($vk, $real); }
            }
        }
    }

    if ($videoSrc !== '') {
        $streamRef = $embedUrl !== '' ? $embedUrl : $url;
        $proxyUrl  = currentScriptUrl('?action=stream&ref=' . urlencode($streamRef) . '&url=' . urlencode($videoSrc));

        header('Content-Type: text/html; charset=utf-8');
        header_remove('X-Frame-Options');
        header_remove('Content-Security-Policy');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1,user-scalable=no">'
           . '<meta name="referrer" content="no-referrer">'
           . '<style>'
           . '*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}'
           . 'html,body{margin:0;padding:0;height:100%;background:#000;overflow:hidden;font-family:system-ui,-apple-system,sans-serif}'
           . 'video{width:100%;height:100%;background:#000;object-fit:contain;display:block;outline:none}'
           . '.topbtns{position:fixed;top:12px;right:12px;display:flex;gap:8px;z-index:15}'
           . '.icobtn{width:42px;height:42px;border-radius:50%;background:rgba(26,10,12,.85);'
           . 'color:#fff;border:1px solid rgba(255,255,255,.18);display:flex;align-items:center;'
           . 'justify-content:center;font-size:18px;cursor:pointer;backdrop-filter:blur(8px);'
           . 'font-family:inherit;padding:0}'
           . '.icobtn:active{transform:scale(.94)}'
           . '.icobtn{box-shadow:5px 5px 12px rgba(0,0,0,.5),inset 2px 2px 5px rgba(255,255,255,.15),inset -3px -3px 6px rgba(0,0,0,.35)}'
           . '.icobtn:hover{background:rgba(127,29,29,.9)}'
           . '#tapOv{position:fixed;left:0;right:0;top:0;bottom:0;display:none;align-items:center;justify-content:center;'
           . 'flex-direction:column;gap:12px;background:transparent;color:#fff;z-index:20;'
           . 'cursor:pointer;text-align:center;padding:12px}'
           . '#tapOv .circle{position:relative;width:66px;height:66px;border-radius:50%;'
           . 'background:linear-gradient(145deg,#ef4444,#991b1b);display:flex;align-items:center;'
           . 'justify-content:center;font-size:26px;padding-left:5px;transition:transform .15s;'
           . 'box-shadow:0 10px 24px rgba(220,38,38,.45),inset 3px 3px 7px rgba(255,255,255,.35),inset -4px -4px 9px rgba(0,0,0,.35)}'
           . '#tapOv .circle::after{content:"";position:absolute;inset:0;border-radius:50%;border:2px solid rgba(239,68,68,.7);animation:ring 1.8s ease-out infinite}'
           . '#tapOv:hover .circle{transform:scale(1.06)}'
           . '@keyframes ring{0%{transform:scale(1);opacity:.9}100%{transform:scale(1.7);opacity:0}}'
           . '#tapOv .ttl{font-size:12px;font-weight:700;letter-spacing:.6px;padding:8px 20px;border-radius:22px;color:#fff;background:linear-gradient(135deg,#ef4444,#991b1b);box-shadow:0 6px 16px rgba(220,38,38,.45),inset 2px 2px 5px rgba(255,255,255,.3),inset -3px -3px 6px rgba(0,0,0,.3)}'
           . '@media(min-width:900px){#tapOv .circle{width:84px;height:84px;font-size:34px;padding-left:7px}#tapOv .ttl{font-size:14px}}'
           . '#settingsSheet{position:fixed;left:0;right:0;bottom:0;background:#1a0a0c;'
           . 'color:#f6eaea;border-top-left-radius:20px;border-top-right-radius:20px;'
           . 'padding:16px 16px 24px;z-index:25;transform:translateY(105%);'
           . 'transition:transform .3s ease;max-height:70vh;overflow-y:auto;'
           . 'box-shadow:0 -10px 40px rgba(0,0,0,.6),inset 3px 3px 8px rgba(255,255,255,.08)}'
           . '#settingsSheet.open{transform:translateY(0)}'
           . '@media(min-width:900px){#settingsSheet{left:50%;right:auto;width:460px;transform:translate(-50%,105%)}#settingsSheet.open{transform:translate(-50%,0)}}'
           . '.sp-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}'
           . '.sp-head h3{font-size:16px;font-weight:800;margin:0;display:flex;align-items:center;gap:8px}'
           . '.sp-close{width:32px;height:32px;border-radius:50%;background:#3a1a1f;color:#fff;'
           . 'border:none;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center}'
           . '.sp-section{margin-bottom:18px}'
           . '.sp-title{font-size:12px;font-weight:800;color:#b09194;'
           . 'text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;display:flex;align-items:center;gap:6px}'
           . '.sp-options{display:flex;flex-wrap:wrap;gap:8px}'
           . '.sp-btn{padding:9px 16px;border-radius:20px;background:#241014;color:#f6eaea;'
           . 'border:1px solid #3a1a1f;font-size:13px;font-weight:700;cursor:pointer;'
           . 'font-family:inherit;transition:all .15s;box-shadow:4px 4px 10px rgba(0,0,0,.45),inset 2px 2px 5px rgba(255,255,255,.12),inset -3px -3px 6px rgba(0,0,0,.3)}'
           . '.sp-btn.active{background:linear-gradient(135deg,#991b1b,#dc2626);color:#fff;'
           . 'border-color:transparent;box-shadow:0 4px 14px rgba(185,28,28,.4)}'
           . '.sp-btn:active{transform:scale(.96)}'
           . '#errOverlay{position:fixed;inset:0;display:none;flex-direction:column;'
           . 'align-items:center;justify-content:center;gap:14px;background:#000;'
           . 'color:#fff;z-index:30;text-align:center;padding:20px}'
           . '#errOverlay button{padding:10px 24px;border-radius:20px;border:none;'
           . 'background:linear-gradient(135deg,#991b1b,#dc2626);color:#fff;'
           . 'font-weight:700;font-size:14px;cursor:pointer;font-family:inherit}'
           . 'video{pointer-events:none}'
           . '#ui{position:fixed;inset:0;z-index:12;display:flex;flex-direction:column;justify-content:flex-end;color:#fff;user-select:none;-webkit-user-select:none;'
           . ' background:linear-gradient(180deg,rgba(0,0,0,.55) 0%,rgba(0,0,0,0) 28%,rgba(0,0,0,0) 55%,rgba(0,0,0,.85) 100%);transition:opacity .3s}'
           . 'body.ui-hide #ui{background:transparent}'
           . 'body.ui-hide .ctl,body.ui-hide .topbtns,body.ui-hide .bar{opacity:0;pointer-events:none}'
           . 'body.tapping #ui{display:none}'
           . '.ctl,.topbtns,.bar{transition:opacity .3s}'
           . '.ctl{position:absolute;left:0;right:0;top:50%;transform:translateY(-50%);display:flex;align-items:center;justify-content:center;gap:34px}'
           . '.cbtn{width:46px;height:46px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;background:rgba(26,10,12,.6);border:1px solid rgba(255,255,255,.16);backdrop-filter:blur(8px);padding:0;cursor:pointer;box-shadow:4px 4px 10px rgba(0,0,0,.45),inset 2px 2px 5px rgba(255,255,255,.14),inset -3px -3px 6px rgba(0,0,0,.35)}'
           . '.cbtn:active{transform:scale(.92)}'
           . '.cbtn svg{width:24px;height:24px}'
           . '.cbtn.big{width:68px;height:68px;border:none;background:linear-gradient(145deg,#ef4444,#991b1b);box-shadow:0 10px 24px rgba(220,38,38,.45),inset 3px 3px 7px rgba(255,255,255,.35),inset -4px -4px 9px rgba(0,0,0,.35)}'
           . '.cbtn.big svg{width:30px;height:30px}'
           . '.bar{position:relative;padding:0 16px 10px}'
           . '.seek{position:relative;height:28px;display:flex;align-items:center;touch-action:none;cursor:pointer}'
           . '.track{position:relative;width:100%;height:5px;border-radius:5px;background:rgba(255,255,255,.22);transition:height .15s}'
           . '.seek.drag .track{height:8px}'
           . '.buf,.fill{position:absolute;left:0;top:0;bottom:0;border-radius:5px;width:0}'
           . '.buf{background:rgba(255,255,255,.35)}'
           . '.fill{background:linear-gradient(90deg,#f87171,#dc2626);box-shadow:0 0 10px rgba(239,68,68,.6)}'
           . '.thumb{position:absolute;top:50%;left:0;width:15px;height:15px;margin:-7.5px 0 0 -7.5px;border-radius:50%;background:#fff;border:3px solid #dc2626;box-shadow:0 2px 8px rgba(0,0,0,.6);transition:transform .15s}'
           . '.seek.drag .thumb{transform:scale(1.35)}'
           . '.row{display:flex;align-items:center;justify-content:space-between;gap:10px}'
           . '.time{font-size:13px;font-weight:700;letter-spacing:.3px;font-variant-numeric:tabular-nums;text-shadow:0 1px 3px rgba(0,0,0,.8)}'
           . '.time span{opacity:.7;font-weight:600}'
           . '.rbtns{display:flex;gap:6px}'
           . '.sbtn{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;padding:0;background:transparent;border:none;cursor:pointer}'
           . '.sbtn:active{background:rgba(255,255,255,.14)}'
           . '.sbtn svg{width:22px;height:22px}'
           . '#spin{position:absolute;left:50%;top:50%;width:46px;height:46px;margin:-23px 0 0 -23px;border-radius:50%;border:4px solid rgba(255,255,255,.2);border-top-color:#ef4444;animation:sp .8s linear infinite;display:none;z-index:13;pointer-events:none}'
           . 'body.buffering #spin{display:block}'
           . 'body.buffering .cbtn.big{opacity:0}'
           . '@keyframes sp{to{transform:rotate(360deg)}}'
           . '</style></head><body>'
           . '<video id="vid" playsinline preload="auto" poster="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw=="></video>'
           . '<div class="topbtns">'
           . '  <button class="icobtn" id="settingsBtn" title="Quality"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg></button>'
           . '</div>'
           . '<div id="ui">'
           . '  <div class="ctl">'
           . '    <button class="cbtn" id="bBack" aria-label="Back 10s"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><text x="12" y="15.2" font-size="7" text-anchor="middle" fill="currentColor" stroke="none" font-weight="700">10</text></svg></button>'
           . '    <button class="cbtn big" id="bPlay" aria-label="Play/Pause"></button>'
           . '    <button class="cbtn" id="bFwd" aria-label="Forward 10s"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><text x="12" y="15.2" font-size="7" text-anchor="middle" fill="currentColor" stroke="none" font-weight="700">10</text></svg></button>'
           . '  </div>'
           . '  <div class="bar">'
           . '    <div class="seek" id="seek"><div class="track"><div class="buf" id="sBuf"></div><div class="fill" id="sFill"></div><div class="thumb" id="sThumb"></div></div></div>'
           . '    <div class="row">'
           . '      <div class="time" id="sTime">0:00 <span>/ 0:00</span></div>'
           . '      <div class="rbtns"><button class="sbtn" id="bMute" aria-label="Mute"></button><button class="sbtn" id="bFs" aria-label="Fullscreen"></button></div>'
           . '    </div>'
           . '  </div>'
           . '</div>'
           . '<div id="spin"></div>'
           . '<div id="tapOv"><div class="ttl">Tap to Play</div></div>'
           . '<div id="settingsSheet">'
           . '  <div class="sp-head"><h3><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg> Quality</h3><button class="sp-close" id="closeSettings" aria-label="Close"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button></div>'
           . '  <div class="sp-section" id="qualitySection">'
           . '    <div class="sp-title"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8M12 17v4"/></svg> Quality</div>'
           . '    <div class="sp-options" id="qualityOptions"></div>'
           . '  </div>'
           . '</div>'
           . '<div id="errOverlay">'
           . '  <div style="line-height:0"><svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.7 18-8-14a2 2 0 0 0-3.4 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-3z"/><path d="M12 9v4M12 17h.01"/></svg></div>'
           . '  <div id="errMsg" style="font-size:14px;opacity:.8">Failed to load video</div>'
           . '  <button id="retryBtn">Retry</button>'
           . '</div>'
           . '<script src="https://cdn.jsdelivr.net/npm/hls.js@1"></script>'
           . '<script>'
           . '(function(){'
           . 'var v=document.getElementById("vid");'
           . 'var tap=document.getElementById("tapOv");'
           . 'var err=document.getElementById("errOverlay");'
           . 'var errMsg=document.getElementById("errMsg");'
           . 'var retry=document.getElementById("retryBtn");'
           . 'var settingsBtn=document.getElementById("settingsBtn");'
           . 'var settingsSheet=document.getElementById("settingsSheet");'
           . 'var closeSettings=document.getElementById("closeSettings");'
           . 'var qualityOptions=document.getElementById("qualityOptions");'
           . 'var src='.json_encode($proxyUrl).';'
           . 'var h=null;'
           . 'function notify(t,extra){var msg={type:t};if(extra)for(var k in extra)msg[k]=extra[k];try{parent.postMessage(msg,"*");}catch(e){}}'
           . 'function showError(msg){errMsg.textContent=msg||"Failed to load video";err.style.display="flex";}'
           . 'function showTap(){tap.style.display="flex";document.body.classList.add("tapping");}'
           . 'function hideTap(){tap.style.display="none";document.body.classList.remove("tapping");}'
           . 'tap.addEventListener("click",function(){hideTap();v.muted=false;v.play().catch(function(e){console.log("play err",e);});});'
           . 'settingsBtn.addEventListener("click",function(){settingsSheet.classList.add("open");});'
           . 'closeSettings.addEventListener("click",function(){settingsSheet.classList.remove("open");});'
           . 'v.addEventListener("playing",function(){hideTap();err.style.display="none";notify("anisol_ready");});'
           . 'v.addEventListener("error",function(){var m=v.error?"Code "+(v.error.code||"?"):"Playback error";showError(m);});'
           . 'function startPlay(){v.play().catch(function(e){console.log("autoplay blocked",e);showTap();});}'
           . 'function toggleFs(){try{var d=document,de=d.documentElement;if(d.fullscreenElement||d.webkitFullscreenElement){(d.exitFullscreen||d.webkitExitFullscreen).call(d);}else{(de.requestFullscreen||de.webkitRequestFullscreen).call(de);}}catch(e){try{v.webkitEnterFullscreen();}catch(x){}}}'
           . 'document.addEventListener("fullscreenchange",function(){try{var o=screen.orientation;if(!o)return;if(document.fullscreenElement){if(o.lock)o.lock("landscape").catch(function(){});}else{if(o.unlock)o.unlock();}}catch(e){}});'
           . 'document.addEventListener("keydown",function(e){'
           . '  var k=e.key;'
           . '  if(k===" "||k==="k"){e.preventDefault();if(v.paused)v.play();else v.pause();}'
           . '  else if(k==="ArrowRight"){e.preventDefault();v.currentTime=Math.min((v.duration||1e9),v.currentTime+10);}'
           . '  else if(k==="ArrowLeft"){e.preventDefault();v.currentTime=Math.max(0,v.currentTime-10);}'
           . '  else if(k==="ArrowUp"){e.preventDefault();v.volume=Math.min(1,v.volume+.1);}'
           . '  else if(k==="ArrowDown"){e.preventDefault();v.volume=Math.max(0,v.volume-.1);}'
           . '  else if(k==="m"||k==="M"){v.muted=!v.muted;}'
           . '  else if(k==="f"||k==="F"){toggleFs();}'
           . '});'
           . 'function buildQualityOptions(){'
           . '  if(!h||!h.levels||h.levels.length<1){document.getElementById("qualitySection").style.display="none";return;}'
           . '  document.getElementById("qualitySection").style.display="block";qualityOptions.innerHTML="";'
           . '  var auto=document.createElement("button");'
           . '  auto.className="sp-btn"+(h.autoLevelEnabled?" active":"");'
           . '  auto.textContent="Auto";'
           . '  auto.onclick=function(){h.currentLevel=-1;buildQualityOptions();settingsSheet.classList.remove("open");};'
           . '  qualityOptions.appendChild(auto);'
           . '  var sorted=h.levels.slice().sort(function(a,b){return (b.height||0)-(a.height||0);});'
           . '  sorted.forEach(function(level){'
           . '    var idx=h.levels.indexOf(level);'
           . '    var b=document.createElement("button");'
           . '    b.className="sp-btn"+(idx===h.currentLevel?" active":"");'
           . '    var lbl=level.height?level.height+"p":(level.name||"Level "+idx);'
           . '    if(level.height>=720)lbl+=" HD";'
           . '    b.textContent=lbl;'
           . '    b.onclick=function(){h.currentLevel=idx;buildQualityOptions();settingsSheet.classList.remove("open");};'
           . '    qualityOptions.appendChild(b);'
           . '  });'
           . '}'
           . 'function sendAudios(){'
           . '  if(!h||!h.audioTracks){notify("anisol_audios",{tracks:[],current:0});return;}'
           . '  var tracks=h.audioTracks.map(function(t){return {name:t.name||"",lang:t.lang||""};});'
           . '  notify("anisol_audios",{tracks:tracks,current:h.audioTrack||0});'
           . '}'
           . 'window.addEventListener("message",function(e){'
           . '  if(!e.data)return;'
           . '  if(e.data.type==="anisol_setAudio" && h){'
           . '    try{ h.audioTrack = e.data.index; sendAudios(); }catch(err){}'
           . '  }'
           . '});'
           . 'function initHls(){'
           . '  if(window.Hls&&Hls.isSupported()){'
           . '    h=new Hls({'
           . '      xhrSetup:function(x){x.withCredentials=false;},'
           . '      maxBufferLength:30,maxMaxBufferLength:60,maxBufferSize:60*1000*1000,maxBufferHole:0.5,'
           . '      manifestLoadingTimeOut:20000,manifestLoadingMaxRetry:4,manifestLoadingRetryDelay:1000,'
           . '      fragLoadingTimeOut:40000,fragLoadingMaxRetry:6,fragLoadingRetryDelay:1000,'
           . '      levelLoadingMaxRetry:4,levelLoadingRetryDelay:1000,'
           . '      enableWorker:true,lowLatencyMode:false,startLevel:-1,capLevelToPlayerSize:false,autoStartLoad:true'
           . '    });'
           . '    h.loadSource(src);h.attachMedia(v);'
           . '    h.on(Hls.Events.MANIFEST_PARSED,function(){'
           . '      buildQualityOptions();'
           . '      sendAudios();'
           . '      h.on(Hls.Events.LEVEL_SWITCHED,buildQualityOptions);'
           . '      h.on(Hls.Events.AUDIO_TRACK_SWITCHED,sendAudios);'
           . '      startPlay();'
           . '    });'
           . '    h.on(Hls.Events.ERROR,function(ev,data){'
           . '      console.log("HLS Error",data);'
           . '      if(data.fatal){'
           . '        if(data.type==="networkError"){'
           . '          showError("Network error — retrying…");'
           . '          setTimeout(function(){try{h.startLoad();err.style.display="none";}catch(e){showError("Stream unavailable");}},3000);'
           . '        } else if(data.type==="mediaError"){try{h.recoverMediaError();}catch(e){showError("Media error");}}'
           . '        else {showError("Playback failed");}'
           . '      }'
           . '    });'
           . '  } else if(v.canPlayType("application/vnd.apple.mpegurl")){'
           . '    v.src=src;'
           . '    v.addEventListener("loadedmetadata",function(){startPlay();});'
           . '  } else {v.src=src;v.addEventListener("loadedmetadata",function(){startPlay();});}'
           . '}'
           . 'retry.addEventListener("click",function(){'
           . '  err.style.display="none";'
           . '  if(h){try{h.destroy();}catch(e){}h=null;}'
           . '  v.removeAttribute("src");v.load();initHls();'
           . '});'
           . 'initHls();'
           . 'setTimeout(function(){if(v.paused&&v.currentTime===0&&!v.error){showTap();}},4000);'
           . 'v.addEventListener("ended",function(){notify("anisol_next");});'
           . '})();'
           . '</script>'
           . '<script>'
           . '(function(){'
           . 'var ui=document.getElementById("ui"),bPlay=document.getElementById("bPlay"),bBack=document.getElementById("bBack"),bFwd=document.getElementById("bFwd"),bMute=document.getElementById("bMute"),bFs=document.getElementById("bFs"),seek=document.getElementById("seek"),sFill=document.getElementById("sFill"),sBuf=document.getElementById("sBuf"),sThumb=document.getElementById("sThumb"),sTime=document.getElementById("sTime");'
           . 'var V=document.getElementById("vid"),drag=false,hideT=null;'
           . 'var IC={play:"<svg viewBox=\\"0 0 24 24\\"><polygon points=\\"7 4 20 12 7 20 7 4\\" fill=\\"currentColor\\" stroke=\\"currentColor\\" stroke-width=\\"1.5\\" stroke-linejoin=\\"round\\"/></svg>",'
           . 'pause:"<svg viewBox=\\"0 0 24 24\\"><rect x=\\"6\\" y=\\"4\\" width=\\"4\\" height=\\"16\\" rx=\\"1.2\\" fill=\\"currentColor\\"/><rect x=\\"14\\" y=\\"4\\" width=\\"4\\" height=\\"16\\" rx=\\"1.2\\" fill=\\"currentColor\\"/></svg>",'
           . 'vol:"<svg viewBox=\\"0 0 24 24\\" fill=\\"none\\" stroke=\\"currentColor\\" stroke-width=\\"2\\" stroke-linecap=\\"round\\" stroke-linejoin=\\"round\\"><path d=\\"M11 5 6 9H2v6h4l5 4V5z\\"/><path d=\\"M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13\\"/></svg>",'
           . 'mute:"<svg viewBox=\\"0 0 24 24\\" fill=\\"none\\" stroke=\\"currentColor\\" stroke-width=\\"2\\" stroke-linecap=\\"round\\" stroke-linejoin=\\"round\\"><path d=\\"M11 5 6 9H2v6h4l5 4V5z\\"/><path d=\\"M22 9l-6 6M16 9l6 6\\"/></svg>",'
           . 'fs:"<svg viewBox=\\"0 0 24 24\\" fill=\\"none\\" stroke=\\"currentColor\\" stroke-width=\\"2\\" stroke-linecap=\\"round\\" stroke-linejoin=\\"round\\"><path d=\\"M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3\\"/></svg>",'
           . 'xfs:"<svg viewBox=\\"0 0 24 24\\" fill=\\"none\\" stroke=\\"currentColor\\" stroke-width=\\"2\\" stroke-linecap=\\"round\\" stroke-linejoin=\\"round\\"><path d=\\"M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3m0 18v-3a2 2 0 0 1 2-2h3M3 16h3a2 2 0 0 1 2 2v3\\"/></svg>"};'
           . 'function fmt(t){if(!isFinite(t)||t<0)t=0;t=Math.floor(t);var h=Math.floor(t/3600),m=Math.floor(t%3600/60),s=t%60;return (h?h+":"+(m<10?"0":""):"")+m+":"+(s<10?"0":"")+s;}'
           . 'function icons(){bPlay.innerHTML=V.paused?IC.play:IC.pause;bMute.innerHTML=V.muted||V.volume===0?IC.mute:IC.vol;bFs.innerHTML=document.fullscreenElement?IC.xfs:IC.fs;}'
           . 'function upd(){var d=V.duration||0,c=V.currentTime||0;if(!drag){var p=d?Math.min(100,c/d*100):0;sFill.style.width=p+"%";sThumb.style.left=p+"%";sTime.innerHTML=fmt(c)+" <span>/ "+fmt(d)+"</span>";}'
           . ' try{if(d&&V.buffered.length){sBuf.style.width=Math.min(100,V.buffered.end(V.buffered.length-1)/d*100)+"%";}}catch(e){}}'
           . 'function showUi(){document.body.classList.remove("ui-hide");clearTimeout(hideT);if(!V.paused)hideT=setTimeout(function(){if(!drag&&!V.paused)document.body.classList.add("ui-hide");},3200);}'
           . 'function toggleUi(){if(document.body.classList.contains("ui-hide"))showUi();else{clearTimeout(hideT);document.body.classList.add("ui-hide");}}'
           . 'function togglePlay(){if(V.paused){V.play().catch(function(){});}else{V.pause();}}'
           . 'function seekBy(n){V.currentTime=Math.max(0,Math.min(V.duration||1e9,V.currentTime+n));upd();showUi();}'
           . 'function fsToggle(){try{var d=document,de=d.documentElement;if(d.fullscreenElement||d.webkitFullscreenElement){(d.exitFullscreen||d.webkitExitFullscreen).call(d);}else{(de.requestFullscreen||de.webkitRequestFullscreen).call(de);}}catch(e){try{V.webkitEnterFullscreen();}catch(x){}}}'
           . 'bPlay.addEventListener("click",function(e){e.stopPropagation();togglePlay();showUi();});'
           . 'bBack.addEventListener("click",function(e){e.stopPropagation();seekBy(-10);});'
           . 'bFwd.addEventListener("click",function(e){e.stopPropagation();seekBy(10);});'
           . 'bMute.addEventListener("click",function(e){e.stopPropagation();V.muted=!V.muted;icons();showUi();});'
           . 'bFs.addEventListener("click",function(e){e.stopPropagation();fsToggle();showUi();});'
           . 'ui.addEventListener("click",function(e){if(e.target===ui)toggleUi();});'
           . 'function ratio(e){var r=seek.getBoundingClientRect();return Math.max(0,Math.min(1,(e.clientX-r.left)/r.width));}'
           . 'function preview(e){var r=ratio(e),d=V.duration||0;sFill.style.width=r*100+"%";sThumb.style.left=r*100+"%";sTime.innerHTML=fmt(r*d)+" <span>/ "+fmt(d)+"</span>";return r;}'
           . 'seek.addEventListener("pointerdown",function(e){e.stopPropagation();drag=true;seek.classList.add("drag");try{seek.setPointerCapture(e.pointerId);}catch(x){}preview(e);clearTimeout(hideT);});'
           . 'seek.addEventListener("pointermove",function(e){if(drag)preview(e);});'
           . 'function endDrag(e){if(!drag)return;var r=preview(e);drag=false;seek.classList.remove("drag");if(V.duration)V.currentTime=r*V.duration;upd();showUi();}'
           . 'seek.addEventListener("pointerup",endDrag);'
           . 'seek.addEventListener("pointercancel",function(){drag=false;seek.classList.remove("drag");upd();});'
           . '["timeupdate","progress","durationchange","loadedmetadata"].forEach(function(n){V.addEventListener(n,upd);});'
           . '["play","pause","volumechange"].forEach(function(n){V.addEventListener(n,function(){icons();showUi();});});'
           . 'V.addEventListener("waiting",function(){document.body.classList.add("buffering");});'
           . '["playing","canplay","seeked"].forEach(function(n){V.addEventListener(n,function(){document.body.classList.remove("buffering");});});'
           . 'document.addEventListener("fullscreenchange",icons);'
           . 'document.addEventListener("fullscreenchange",function(){try{var o=screen.orientation;if(!o)return;if(document.fullscreenElement){if(o.lock)o.lock("landscape").catch(function(){});}else{if(o.unlock)o.unlock();}}catch(e){}});'
           . 'document.addEventListener("mousemove",function(){if(!V.paused)showUi();});'
           . 'icons();upd();showUi();'
           . '})();'
           . '</script></body></html>';
        exit;
    }

    if ($embedUrl !== '') {
        header('Content-Type: text/html; charset=utf-8');
        header_remove('X-Frame-Options');
        header_remove('Content-Security-Policy');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1,user-scalable=no">'
           . '<meta name="referrer" content="no-referrer">'
           . '<style>html,body{margin:0;padding:0;height:100%;background:#000;overflow:hidden;font-family:system-ui,sans-serif}'
           . '#pif{position:absolute;inset:0;width:100%;height:100%;border:0;visibility:hidden}'
           . '#tapOv{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;'
           . 'background:transparent;color:#fff;z-index:10;cursor:pointer;padding:12px;text-align:center}'
           . '#tapOv.hide{opacity:0;pointer-events:none;transition:opacity .35s}'
           . '#tapOv .circle{position:relative;width:66px;height:66px;border-radius:50%;background:linear-gradient(145deg,#ef4444,#991b1b);'
           . 'display:flex;align-items:center;justify-content:center;font-size:26px;padding-left:5px;'
           . 'box-shadow:0 10px 24px rgba(220,38,38,.45),inset 3px 3px 7px rgba(255,255,255,.35),inset -4px -4px 9px rgba(0,0,0,.35)}'
           . '#tapOv .circle::after{content:"";position:absolute;inset:0;border-radius:50%;border:2px solid rgba(239,68,68,.7);animation:ring 1.8s ease-out infinite}'
           . '@keyframes ring{0%{transform:scale(1);opacity:.9}100%{transform:scale(1.7);opacity:0}}'
           . '#tapOv .ttl{font-size:12px;font-weight:700;letter-spacing:.6px;padding:8px 20px;border-radius:22px;color:#fff;background:linear-gradient(135deg,#ef4444,#991b1b);box-shadow:0 6px 16px rgba(220,38,38,.45),inset 2px 2px 5px rgba(255,255,255,.3),inset -3px -3px 6px rgba(0,0,0,.3)}'
           . '@media(min-width:900px){#tapOv .circle{width:84px;height:84px;font-size:34px;padding-left:7px}#tapOv .ttl{font-size:14px}}</style></head>'
           . '<body><iframe id="pif" src="'.htmlspecialchars($embedUrl, ENT_QUOTES).'" allowfullscreen '
           . 'allow="autoplay; encrypted-media; picture-in-picture; fullscreen" referrerpolicy="no-referrer" scrolling="no"></iframe>'
           . '<div id="tapOv"><div class="ttl">Tap to Play</div></div>'
           . '<script>var tap=document.getElementById("tapOv");'
           . 'function notify(){try{parent.postMessage({type:"anisol_ready"},"*");}catch(e){}}'
           . 'tap.addEventListener("click",function(){document.getElementById("pif").style.visibility="visible";tap.classList.add("hide");notify();});'
           . 'setTimeout(notify,2000);</script></body></html>';
        exit;
    }

    $base = '<base href="' . htmlspecialchars($url, ENT_QUOTES) . '">';
    if ($html !== '' && preg_match('/<head\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1] + strlen($m[0][0]);
        $html = substr($html, 0, $pos) . $base . substr($html, $pos);
    } else { $html = '<!DOCTYPE html><html><head>'.$base.'</head><body>'.($html ?: '<p>Unable to load player</p>').'</body></html>'; }
    header('Content-Type: text/html; charset=utf-8');
    header_remove('X-Frame-Options');
    header_remove('Content-Security-Policy');
    echo $html;
    exit;
}

function handlePlayerDebug(): void {
    header('Content-Type: text/plain; charset=utf-8');
    $url = $_GET['url'] ?? '';
    if ($url === '') { echo "Pass ?url=EPISODE_URL"; return; }
    echo "URL: $url\n\n";
    $html = fetchUrl($url, 25);
    if ($html === null) { echo "FETCH FAILED\n"; return; }
    echo "=== IFRAMES ===\n";
    if (preg_match_all('#<iframe[^>]+>#i', $html, $mm)) foreach ($mm[0] as $tag) echo $tag . "\n\n";
}

function handleEmbedDebug(): void {
    header('Content-Type: text/plain; charset=utf-8');
    $url = $_GET['url'] ?? '';
    $referer = $_GET['referer'] ?? BASE_URL;
    if ($url === '') { echo "Pass ?url=EMBED_URL&referer=REFERER"; return; }
    $html = fetchUrl($url, 20, $referer);
    if ($html === null) { echo "FETCH FAILED\n"; return; }
    $found = extractVideoFromEmbed($url, $referer);
    echo $found !== '' ? $found . "\n" : "(none)\n";
}

/* ============================================================
 *  FRONTEND UI
 * ============================================================ */

function renderUi(): void {
    header('Content-Type: text/html; charset=utf-8');
    $self    = basename(__FILE__);
    $logoSrc = 'https://mir-s3-cdn-cf.behance.net/projects/404/03dd04226196179.682ace595875c.jpg';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,viewport-fit=cover">
<meta name="theme-color" content="#240a0f">
<meta name="google-site-verification" content="9kECW09R9M0mg3VtH77I3xbrqeu_iQ0YjqUKi0ipaMo">
<meta name="description" content="ANISOL — Watch anime online free in Hindi, Tamil, Telugu, English &amp; Japanese. Stream HD anime episodes with Multi-Audio support. Best source for Hindi dubbed anime.">
<meta name="keywords" content="watch anime online, anime hindi dubbed, anime tamil dubbed, anime telugu dubbed, free anime streaming, HD anime, anime episodes, ANISOL, watch anime free">
<meta name="robots" content="follow, index, max-snippet:-1, max-video-preview:-1, max-image-preview:large">
<meta name="author" content="ANISOL">
<link rel="canonical" href="https://anisol.ct.ws/">
<meta property="og:locale" content="en_US">
<meta property="og:type" content="website">
<meta property="og:title" content="ANISOL — Watch Anime Online Free in Hindi, Tamil, Telugu">
<meta property="og:description" content="Watch anime online free in Hindi, Tamil, Telugu, English &amp; Japanese. Stream HD anime episodes with Multi-Audio support.">
<meta property="og:url" content="https://anisol.ct.ws/">
<meta property="og:site_name" content="ANISOL">
<meta property="og:image" content="https://mir-s3-cdn-cf.behance.net/projects/404/03dd04226196179.682ace595875c.jpg">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="ANISOL — Watch Anime Online Free">
<meta name="twitter:description" content="Watch anime online free in Hindi, Tamil, Telugu, English &amp; Japanese. HD Multi-Audio streaming.">
<meta name="twitter:image" content="https://mir-s3-cdn-cf.behance.net/projects/404/03dd04226196179.682ace595875c.jpg">
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "WebSite",
      "@id": "https://anisol.ct.ws/#website",
      "url": "https://anisol.ct.ws/",
      "name": "ANISOL",
      "alternateName": "Watch Anime Online",
      "description": "Watch anime online free in Hindi, Tamil, Telugu, English & Japanese.",
      "inLanguage": "en-US",
      "potentialAction": {
        "@type": "SearchAction",
        "target": "https://anisol.ct.ws/?s={search_term_string}",
        "query-input": "required name=search_term_string"
      }
    },
    {
      "@type": "Organization",
      "@id": "https://anisol.ct.ws/#org",
      "name": "ANISOL",
      "url": "https://anisol.ct.ws/",
      "logo": {
        "@type": "ImageObject",
        "url": "https://mir-s3-cdn-cf.behance.net/projects/404/03dd04226196179.682ace595875c.jpg"
      }
    }
  ]
}
</script>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Russo+One&display=swap" rel="stylesheet">
<title>ANISOL — Watch Anime Online Free in Hindi, Tamil, Telugu, English HD</title>
<link rel="icon" href="<?= htmlspecialchars($logoSrc, ENT_QUOTES) ?>">
<style>
:root{--bg:#0d0506;--surface:#1a0a0c;--surface-2:#241014;--border:#3a1a1f;--text:#f6eaea;--text-dim:#b09194;--accent:#b91c1c;--accent-2:#ef4444;--accent-grad:linear-gradient(135deg,#991b1b,#dc2626);--danger:#fca5a5;--radius:16px;--radius-sm:10px;--safe-bottom:env(safe-area-inset-bottom,0px);--player-h:0px;}
body.light{--bg:#f8f4f4;--surface:#fff;--surface-2:#f3eaea;--border:#e6d5d6;--text:#1a0a0c;--text-dim:#7a6366;--accent:#b91c1c;--accent-2:#dc2626;--accent-grad:linear-gradient(135deg,#991b1b,#dc2626);--danger:#dc2626;}
*{box-sizing:border-box;margin:0;padding:0}
html,body{background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;-webkit-tap-highlight-color:transparent;overflow-x:hidden;transition:background .25s,color .25s}
body{padding-bottom:calc(72px + var(--safe-bottom));min-height:100vh}
a{color:inherit;text-decoration:none}button{font-family:inherit;cursor:pointer;border:none;background:none;color:inherit}img{display:block;max-width:100%}
::-webkit-scrollbar{display:none}*{scrollbar-width:none}
.app-header{position:sticky;top:0;z-index:60;background:rgba(13,5,6,0.88);backdrop-filter:blur(14px);border-bottom:1px solid var(--border);padding:14px 16px}
body.light .app-header{background:rgba(255,255,255,0.92)}
.header-row{display:flex;align-items:center;justify-content:space-between;gap:10px}
.brand{display:flex;align-items:center;gap:12px}
.brand-logo{width:48px;height:48px;border-radius:12px;object-fit:cover;border:1px solid var(--border);flex:0 0 auto}
.brand-logo-fallback{width:48px;height:48px;border-radius:12px;background:var(--accent-grad);display:none;align-items:center;justify-content:center;font-size:24px;color:#fff;flex:0 0 auto}
.brand-name{font-size:24px;font-weight:900;letter-spacing:2px}
.search-box{margin-top:12px;position:relative}
.search-box input{width:100%;padding:12px 42px 12px 16px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);font-size:15px;outline:none}
.search-box input:focus{border-color:var(--accent)}
.search-box input::placeholder{color:var(--text-dim)}
.search-icon{position:absolute;right:14px;top:50%;transform:translateY(-50%);font-size:16px;color:var(--text-dim);pointer-events:none}
.search-clear{position:absolute;right:40px;top:50%;transform:translateY(-50%);font-size:14px;color:var(--text-dim);display:none}
.page{display:none;animation:fadeIn .25s ease}.page.active{display:block}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.section{padding:18px 16px 4px}
.section-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.section-title h2{font-size:17px;font-weight:800}
.section-title .tag{font-size:11px;color:var(--accent-2);background:rgba(239,68,68,0.12);padding:3px 9px;border-radius:20px;font-weight:700}
.hero-slider{margin:14px 16px 0;border-radius:var(--radius);overflow:hidden;position:relative;height:210px;background:var(--surface)}
.hero-track{display:flex;height:100%;transition:transform .45s cubic-bezier(.4,0,.2,1)}
.hero-slide{min-width:100%;height:100%;position:relative;background-size:cover;background-position:center;display:flex;align-items:flex-end;background-color:var(--surface-2)}
.hero-slide::after{content:'';position:absolute;inset:0;background:linear-gradient(0deg,rgba(0,0,0,.92),rgba(0,0,0,.35) 55%,rgba(0,0,0,.05))}
.hero-info{position:relative;z-index:2;padding:16px;width:100%;color:#fff}
.hero-badge{display:inline-block;font-size:10px;font-weight:800;background:var(--accent-grad);color:#fff;padding:3px 8px;border-radius:6px;margin-bottom:6px}
.hero-info h3{font-size:19px;font-weight:800;text-shadow:0 2px 8px rgba(0,0,0,.6)}
.hero-dots{position:absolute;bottom:10px;right:14px;z-index:3;display:flex;gap:5px}
.hero-dot{width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,.4);transition:all .3s}
.hero-dot.active{background:#fff;width:16px;border-radius:4px}
.h-scroll{display:flex;gap:12px;overflow-x:auto;padding:2px 16px 12px;scroll-snap-type:x proximity}
.anime-card{scroll-snap-align:start;flex:0 0 auto;width:128px;cursor:pointer}
.anime-card .thumb-wrap{position:relative;width:128px;height:172px;border-radius:var(--radius-sm);overflow:hidden;background:var(--surface-2);border:1px solid var(--border)}
.anime-card img{width:100%;height:100%;object-fit:cover;opacity:0;transition:opacity .3s}
.anime-card img.loaded{opacity:1}
.ep-badge{position:absolute;bottom:6px;left:6px;font-size:10px;font-weight:700;background:rgba(13,5,6,.85);padding:2px 7px;border-radius:6px;color:#ef4444}
.anime-card .card-title{margin-top:8px;font-size:12.5px;font-weight:600;line-height:1.3;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.anime-card .card-sub{font-size:10.5px;color:var(--text-dim);margin-top:2px}
.skeleton{background:linear-gradient(90deg,var(--surface-2) 25%,var(--border) 37%,var(--surface-2) 63%);background-size:400% 100%;animation:shimmer 1.4s ease infinite}
@keyframes shimmer{0%{background-position:100% 0}100%{background-position:0 0}}
.sk-card{width:128px;flex:0 0 auto}.sk-thumb{width:128px;height:172px;border-radius:var(--radius-sm)}
.sk-line{height:10px;border-radius:4px;margin-top:8px}.sk-line.w1{width:90%}.sk-line.w2{width:60%;margin-top:6px}
.empty-state{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:40px 24px;text-align:center;color:var(--text-dim)}
.empty-state .emoji{font-size:44px;margin-bottom:14px}
.empty-state h3{color:var(--text);font-size:16px;margin-bottom:6px}
.empty-state p{font-size:13px;line-height:1.5}
.empty-state button{margin-top:16px;padding:10px 20px;background:var(--accent-grad);color:#fff;border-radius:20px;font-weight:700;font-size:13px}
.search-results{padding:12px 16px 20px}
.result-card{display:flex;gap:12px;padding:12px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);margin-bottom:10px;cursor:pointer}
.result-card .thumb{width:70px;height:96px;border-radius:8px;overflow:hidden;flex:0 0 auto;background:var(--surface-2)}
.result-card .thumb img{width:100%;height:100%;object-fit:cover}
.result-info h4{font-size:14px;font-weight:700;margin-bottom:3px}
.result-info .meta{font-size:11px;color:var(--accent-2);margin-bottom:4px}
.details-content{padding:20px 16px 20px}
.details-poster-row{display:flex;gap:14px;align-items:flex-end}
.details-poster{width:104px;height:148px;border-radius:12px;overflow:hidden;flex:0 0 auto;border:2px solid var(--surface);box-shadow:0 8px 20px rgba(0,0,0,.15);background:var(--surface-2)}
.details-poster img{width:100%;height:100%;object-fit:cover}
.details-title-block{padding-bottom:6px;flex:1;min-width:0}
.details-title-block h1{font-size:19px;font-weight:800;line-height:1.25}
.status-pill{display:inline-block;margin-top:6px;font-size:10.5px;font-weight:700;padding:3px 9px;border-radius:20px;background:rgba(185,28,28,.15);color:var(--accent)}
.season-selector{display:flex;gap:8px;margin-top:16px;overflow-x:auto;padding-bottom:2px}
.season-chip{flex:0 0 auto;padding:8px 16px;border-radius:20px;font-size:12.5px;font-weight:700;background:var(--surface-2);border:1px solid var(--border);color:var(--text-dim)}
.season-chip.active{background:var(--accent-grad);color:#fff;border-color:transparent}
.episode-list{margin-top:14px;display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.episode-item{display:flex;align-items:center;justify-content:center;position:relative;height:56px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;font-size:15px;font-weight:800}
.episode-item .watched-tick{position:absolute;top:3px;right:5px;color:var(--accent-2);font-size:10px}
.episode-item.now-playing{border-color:var(--accent);background:rgba(185,28,28,.15);color:var(--accent)}
.movie-hero{display:flex;flex-direction:column;align-items:center;text-align:center;padding:24px 16px 32px;gap:20px}
.movie-poster-lg{width:170px;height:250px;border-radius:18px;overflow:hidden;flex:0 0 auto;border:2px solid var(--border);background:var(--surface-2);box-shadow:0 16px 40px rgba(0,0,0,.35)}
.movie-poster-lg img{width:100%;height:100%;object-fit:cover}
.movie-title-lg{font-size:22px;font-weight:800;line-height:1.3;max-width:320px}
.movie-sub{font-size:13px;color:var(--text-dim);line-height:1.5;max-width:300px}
.play-btn-lg{display:inline-flex;align-items:center;gap:10px;padding:16px 40px;border-radius:50px;background:var(--accent-grad);color:#fff;font-size:16px;font-weight:800;box-shadow:0 12px 30px rgba(185,28,28,.4);transition:transform .15s}
.play-btn-lg:active{transform:scale(.96)}
#playerSpacer{display:none;height:0;width:100%}
body.player-open #playerSpacer{display:block;height:var(--player-h,0px)}
#nativePlayerOverlay{display:none;position:fixed;top:0;left:0;right:0;z-index:150;background:#000;box-shadow:0 10px 30px rgba(0,0,0,.6)}
#nativePlayerOverlay .accent-strip{height:4px;background:var(--accent-grad)}
#nativePlayerOverlay .ratio-wrap{position:relative;width:100%;padding-top:56.25%;background:#000;overflow:hidden}
#nativePlayerFrame{position:absolute;inset:0;width:100%;height:100%;border:0;z-index:2}
#nativePlayerLoading{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;background:#000;color:#fff;z-index:1;font-size:14px;font-weight:600;letter-spacing:1px;transition:opacity .3s}
#nativePlayerLoading.hide{opacity:0;pointer-events:none}
.loading-dots{display:inline-flex;gap:5px}
.loading-dots span{width:8px;height:8px;border-radius:50%;background:var(--accent-2);animation:bounce 1.2s infinite ease-in-out}
.loading-dots span:nth-child(2){animation-delay:.15s;background:var(--accent)}
.loading-dots span:nth-child(3){animation-delay:.3s;background:var(--accent-2)}
@keyframes bounce{0%,80%,100%{transform:scale(.5);opacity:.4}40%{transform:scale(1);opacity:1}}
.lang-bar{display:none;gap:8px;padding:10px 12px;background:#140708;border-top:1px solid #3a1a1f;overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch;scrollbar-width:none;border-bottom-left-radius:20px;border-bottom-right-radius:20px;align-items:center}
.lang-bar::-webkit-scrollbar{display:none}
.lang-bar.show{display:flex}
.lang-label{flex:0 0 auto;display:flex;align-items:center;font-size:11px;font-weight:700;color:#b09194;letter-spacing:.5px;text-transform:uppercase;padding-right:4px}
.lang-chip{flex:0 0 auto;padding:8px 14px;border-radius:20px;font-size:12.5px;font-weight:700;background:#241014;border:1px solid #3a1a1f;color:#b09194;white-space:nowrap;cursor:pointer;transition:all .15s;font-family:inherit}
.lang-chip.active{background:linear-gradient(135deg,#991b1b,#dc2626);color:#fff;border-color:transparent;box-shadow:0 4px 14px rgba(185,28,28,.35)}
.lang-chip:active{transform:scale(.96)}
.history-grid{padding:12px 16px 20px;display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
.history-card{position:relative;cursor:pointer}
.history-card .thumb-wrap{width:100%;height:150px;border-radius:var(--radius-sm);overflow:hidden;background:var(--surface-2);border:1px solid var(--border);position:relative}
.history-card img{width:100%;height:100%;object-fit:cover}
.history-card .remove-btn{position:absolute;top:6px;right:6px;width:26px;height:26px;border-radius:50%;background:rgba(13,5,6,.75);display:flex;align-items:center;justify-content:center;font-size:12px;color:#fff}
.history-card .card-title{margin-top:8px;font-size:12.5px;font-weight:600}
.history-card .card-sub{font-size:10.5px;color:var(--text-dim);margin-top:2px}
.settings-list{padding:12px 16px}
.settings-group{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:16px;overflow:hidden}
.settings-row{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--border);font-size:14px}
.settings-row:last-child{border-bottom:none}
.settings-row .label{font-weight:600}
.settings-row .desc{font-size:11.5px;color:var(--text-dim);margin-top:2px}
.toggle{width:44px;height:26px;border-radius:20px;background:var(--surface-2);border:1px solid var(--border);position:relative;transition:background .2s;flex:0 0 auto}
.toggle.on{background:var(--accent-grad);border-color:transparent}
.toggle .knob{position:absolute;top:2px;left:2px;width:20px;height:20px;border-radius:50%;background:#fff;transition:transform .2s;box-shadow:0 1px 3px rgba(0,0,0,.2)}
.toggle.on .knob{transform:translateX(18px)}
.settings-danger{color:var(--danger);font-weight:700;text-align:center;padding:14px;font-size:13.5px}
.settings-secondary{color:var(--accent);font-weight:700;text-align:center;padding:14px;font-size:13.5px}
.bottom-nav{position:fixed;bottom:0;left:0;right:0;z-index:100;display:flex;background:rgba(13,5,6,.92);backdrop-filter:blur(16px);border-top:1px solid var(--border);padding:8px 6px calc(8px + var(--safe-bottom))}
body.light .bottom-nav{background:rgba(255,255,255,.96)}
.nav-btn{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;font-size:10.5px;font-weight:600;color:var(--text-dim);padding:6px 0;border-radius:12px}
.nav-btn .nav-ic{font-size:19px}
.nav-btn.active{color:var(--accent-2)}
.toast{position:fixed;bottom:calc(84px + var(--safe-bottom));left:50%;transform:translateX(-50%) translateY(20px);background:var(--surface-2);border:1px solid var(--border);padding:10px 18px;border-radius:20px;font-size:12.5px;opacity:0;transition:all .3s;z-index:200;white-space:nowrap;box-shadow:0 8px 20px rgba(0,0,0,.15)}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
@media(min-width:480px){.anime-card,.sk-card{width:140px}.anime-card .thumb-wrap,.sk-thumb{width:140px;height:188px}.movie-poster-lg{width:200px;height:290px}}

/* ===== Desktop / PC layout ===== */
@media(min-width:900px){
  body{padding-bottom:110px}
  .app-header{display:flex;align-items:center;justify-content:space-between;gap:24px;padding:12px max(24px,calc((100vw - 1280px)/2 + 24px))}
  .search-box{margin-top:0;flex:1;max-width:480px}
  main{max-width:1280px;margin:0 auto}
  .section{padding:22px 24px 6px}
  .hero-slider{margin:18px 24px 0;height:380px}
  .hero-info{padding:28px}
  .hero-info h3{font-size:28px}
  .hero-badge{font-size:12px}
  .h-scroll{flex-wrap:wrap;overflow-x:visible;padding:2px 24px 14px;gap:18px}
  .anime-card,.sk-card{width:168px}
  .anime-card .thumb-wrap,.sk-thumb{width:168px;height:226px}
  .anime-card .thumb-wrap{transition:border-color .2s,transform .2s}
  .anime-card:hover .thumb-wrap{border-color:var(--accent);transform:translateY(-3px)}
  .anime-card .card-title{font-size:13.5px}
  .search-results{padding:16px 24px 24px;display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:12px}
  .search-results .empty-state{grid-column:1/-1}
  .result-card{margin-bottom:0}
  .result-card:hover,.episode-item:hover,.season-chip:hover{border-color:var(--accent)}
  .details-content{max-width:1000px;margin:0 auto;padding:24px}
  .details-poster{width:150px;height:214px}
  .details-title-block h1{font-size:26px}
  .season-selector{flex-wrap:wrap}
  .episode-list{grid-template-columns:repeat(auto-fill,minmax(72px,1fr))}
  .history-grid{grid-template-columns:repeat(auto-fill,minmax(180px,1fr));padding:12px 24px 24px;gap:18px}
  .history-card .thumb-wrap{height:240px}
  .settings-list{max-width:640px;margin:0 auto}
  .bottom-nav{left:50%;right:auto;transform:translateX(-50%);width:480px;bottom:16px;border:1px solid var(--border);border-radius:20px;padding:8px 10px}
  .nav-btn:hover{color:var(--accent-2)}
  .toast{bottom:104px}
  #nativePlayerOverlay .ratio-wrap{padding-top:0;height:auto;aspect-ratio:16/9;width:min(100%,1100px,calc((100vh - 170px)*1.7778));margin:0 auto}
  .lang-bar{border-radius:0;justify-content:center}
}

/* ===== Claymorphism UI ===== */
:root{--bg:#240a0f;--surface:#33141a;--surface-2:#3f1a21;--border:rgba(255,255,255,.05);--text-dim:#c4a0a4;
--clay-out:9px 9px 20px rgba(0,0,0,.55),-6px -6px 16px rgba(255,130,130,.07);
--clay-sm:5px 5px 12px rgba(0,0,0,.5),-4px -4px 10px rgba(255,130,130,.06);
--clay-in:inset 3px 3px 7px rgba(255,255,255,.14),inset -5px -5px 10px rgba(0,0,0,.38);
--clay-press:inset 5px 5px 11px rgba(0,0,0,.55),inset -4px -4px 9px rgba(255,130,130,.07);
--clay-glow:0 10px 24px rgba(220,38,38,.38);--rc:26px}
body.light{--bg:#f4dede;--surface:#fbeaea;--surface-2:#f6dada;--border:rgba(185,28,28,.06);--text-dim:#8a6366;
--clay-out:9px 9px 20px rgba(160,80,80,.28),-7px -7px 16px rgba(255,255,255,.95);
--clay-sm:5px 5px 12px rgba(160,80,80,.25),-4px -4px 10px rgba(255,255,255,.9);
--clay-in:inset 3px 3px 7px rgba(255,255,255,.95),inset -5px -5px 10px rgba(185,28,28,.12);
--clay-press:inset 5px 5px 11px rgba(160,80,80,.3),inset -4px -4px 9px rgba(255,255,255,.9);
--clay-glow:0 10px 24px rgba(220,38,38,.3)}
.app-header{background:var(--bg);backdrop-filter:none;border-bottom:none;border-radius:0 0 30px 30px;box-shadow:0 10px 24px rgba(0,0,0,.4),inset 0 -3px 8px rgba(255,255,255,.05)}
body.light .app-header{background:var(--bg);box-shadow:0 10px 24px rgba(160,80,80,.22)}
.brand-logo,.brand-logo-fallback{border:none;border-radius:20px;box-shadow:var(--clay-sm),var(--clay-in)}
.brand-name{text-shadow:2px 2px 4px rgba(0,0,0,.45),-1px -1px 2px rgba(255,150,150,.12)}
.search-box input{background:var(--surface);border:none;border-radius:30px;padding:14px 46px 14px 20px;box-shadow:var(--clay-press);transition:box-shadow .2s}
.search-box input:focus{box-shadow:var(--clay-press),0 0 0 3px rgba(239,68,68,.35)}
.section-title .tag,.hero-badge,.status-pill,.ep-badge{border-radius:14px;box-shadow:var(--clay-in)}
.section-title .tag{background:var(--surface-2);box-shadow:var(--clay-sm),var(--clay-in)}
.hero-slider{border-radius:34px;box-shadow:var(--clay-out),var(--clay-in)}
.hero-dot{box-shadow:inset 1px 1px 2px rgba(255,255,255,.4)}
.anime-card .thumb-wrap,.history-card .thumb-wrap{border:none;border-radius:var(--rc);background:var(--surface);box-shadow:var(--clay-out),var(--clay-in);transition:transform .2s,box-shadow .2s}
.anime-card:active .thumb-wrap,.history-card:active .thumb-wrap{transform:scale(.96);box-shadow:var(--clay-sm),var(--clay-in)}
.anime-card img,.history-card img{border-radius:var(--rc)}
.anime-card .card-title,.history-card .card-title{margin-top:12px;padding:0 4px}
.sk-thumb{border-radius:var(--rc);box-shadow:var(--clay-sm)}
.result-card{border:none;border-radius:var(--rc);padding:14px;background:linear-gradient(145deg,var(--surface-2),var(--surface));box-shadow:var(--clay-out),var(--clay-in);margin-bottom:16px}
.result-card:active{transform:scale(.98)}
.result-card .thumb{border-radius:18px;box-shadow:var(--clay-sm)}
.details-poster{border:none;border-radius:26px;box-shadow:var(--clay-out),var(--clay-in)}
.movie-poster-lg{border:none;border-radius:34px;box-shadow:var(--clay-out),var(--clay-in)}
.season-chip{border:none;border-radius:22px;padding:10px 20px;background:var(--surface-2);box-shadow:var(--clay-sm),var(--clay-in);color:var(--text-dim)}
.season-chip.active{background:var(--accent-grad);color:#fff;box-shadow:var(--clay-glow),var(--clay-in)}
.episode-list{gap:14px}
.episode-item{border:none;border-radius:22px;height:60px;background:linear-gradient(145deg,var(--surface-2),var(--surface));box-shadow:var(--clay-sm),var(--clay-in);transition:transform .15s,box-shadow .15s}
.episode-item:active{transform:scale(.94);box-shadow:var(--clay-press)}
.episode-item.now-playing{background:var(--accent-grad);color:#fff;box-shadow:var(--clay-glow),var(--clay-in)}
.episode-item .watched-tick{color:#fff;background:var(--accent);border-radius:50%;width:16px;height:16px;display:flex;align-items:center;justify-content:center;top:5px;right:6px;box-shadow:var(--clay-in)}
.play-btn-lg,.empty-state button{border-radius:40px;box-shadow:var(--clay-glow),var(--clay-in)}
.play-btn-lg{padding:18px 46px}
.empty-state button{padding:12px 26px}
.settings-group{border:none;border-radius:30px;background:linear-gradient(145deg,var(--surface-2),var(--surface));box-shadow:var(--clay-out),var(--clay-in)}
.settings-row{border-bottom:1px solid rgba(255,255,255,.05)}
body.light .settings-row{border-bottom:1px solid rgba(185,28,28,.08)}
.settings-danger,.settings-secondary{font-weight:800}
.toggle{width:54px;height:30px;border:none;background:var(--surface);box-shadow:var(--clay-press)}
.toggle.on{background:var(--accent-grad);box-shadow:inset 3px 3px 7px rgba(0,0,0,.35),inset -2px -2px 6px rgba(255,255,255,.15)}
.toggle .knob{top:3px;left:3px;width:24px;height:24px;background:linear-gradient(145deg,#fff,#e6cfcf);box-shadow:3px 3px 7px rgba(0,0,0,.45),inset -2px -2px 4px rgba(0,0,0,.12)}
.toggle.on .knob{transform:translateX(24px)}
.bottom-nav{left:12px;right:12px;bottom:calc(12px + var(--safe-bottom));padding:8px;border:none;border-radius:32px;background:var(--surface);backdrop-filter:none;box-shadow:var(--clay-out),var(--clay-in)}
body.light .bottom-nav{background:var(--surface)}
body{padding-bottom:calc(100px + var(--safe-bottom))}
.nav-btn{border-radius:24px;padding:8px 0;transition:all .2s}
.nav-btn.active{color:#fff;background:var(--accent-grad);box-shadow:var(--clay-glow),var(--clay-in)}
.toast{border:none;border-radius:24px;padding:12px 22px;background:var(--surface-2);box-shadow:var(--clay-out),var(--clay-in);bottom:calc(104px + var(--safe-bottom))}
#nativePlayerOverlay{border-radius:0 0 30px 30px;overflow:hidden;box-shadow:0 14px 30px rgba(0,0,0,.55)}
.lang-bar{background:var(--surface);border-top:none;border-radius:0;padding:12px 14px}
.lang-chip{border:none;border-radius:20px;background:var(--surface-2);box-shadow:var(--clay-sm),var(--clay-in)}
.lang-chip.active{background:var(--accent-grad);box-shadow:var(--clay-glow),var(--clay-in)}
.history-card .remove-btn{background:var(--surface-2);box-shadow:var(--clay-sm),var(--clay-in)}
.empty-state .emoji{filter:drop-shadow(4px 6px 6px rgba(0,0,0,.45))}
@media(min-width:900px){
  .app-header{padding:14px max(24px,calc((100vw - 1280px)/2 + 24px))}
  .bottom-nav{left:50%;right:auto;width:480px;transform:translateX(-50%);bottom:18px}
  .toast{bottom:110px}
  .anime-card:hover .thumb-wrap{transform:translateY(-4px);border:none}
  .result-card:hover,.episode-item:hover,.season-chip:hover{border:none;transform:translateY(-2px)}
}

/* ===== Brand + Newest Drops grid ===== */
.brand{gap:12px;padding:6px 22px 6px 6px;border-radius:40px;background:var(--surface);box-shadow:var(--clay-sm),var(--clay-in)}
.brand-logo-wrap{position:relative;width:52px;height:52px;border-radius:50%;overflow:hidden;background:#fff;flex:0 0 auto;border:3px solid var(--surface-2);box-shadow:3px 3px 8px rgba(0,0,0,.35)}
.brand-logo-wrap .brand-logo{width:100%;height:100%;border-radius:0;box-shadow:none;object-fit:cover;}
.brand-logo-wrap .brand-logo-fallback{width:100%;height:100%;border-radius:0;box-shadow:none;font-size:22px}
.brand-name{font-size:26px;font-weight:900;letter-spacing:3px;line-height:1;background:linear-gradient(135deg,#ff8a8a 0%,#ef4444 45%,#c81e1e 100%);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:transparent;text-shadow:none;filter:drop-shadow(1px 2px 2px rgba(0,0,0,.35))}
body.light .brand-name{background-image:linear-gradient(135deg,#dc2626 0%,#b91c1c 55%,#7f1d1d 100%);filter:drop-shadow(1px 2px 2px rgba(160,80,80,.3))}
#latestRow{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:20px 16px;overflow:visible;scroll-snap-type:none;padding:2px 16px 16px}
#latestRow .anime-card,#latestRow .sk-card{width:100%}
#latestRow .anime-card .thumb-wrap,#latestRow .sk-thumb{width:100%;height:auto;aspect-ratio:3/4}
@media(min-width:900px){#latestRow{grid-template-columns:repeat(auto-fill,minmax(170px,1fr));padding:2px 24px 20px;gap:24px 20px}}

/* ===== Icons + welcome popup ===== */
.ic{width:1em;height:1em;display:inline-block;vertical-align:-.15em;flex:0 0 auto}
.nav-btn .nav-ic{font-size:22px;line-height:1;display:flex}
.search-icon{display:flex;font-size:18px}
.search-clear{font-size:16px;line-height:0}
.empty-state .emoji{color:var(--accent)}
.empty-state .emoji .ic{stroke-width:1.5}
.episode-item .watched-tick .ic{stroke-width:3.5}
.history-card .remove-btn .ic{stroke-width:2.5}
.play-btn-lg .ic{width:20px;height:20px}
.lang-label .ic{margin-right:5px;font-size:14px}
.movie-sub .ic{margin-right:4px}
.brand-logo-fallback .ic{width:24px;height:24px}
.brand-name{font-family:'Russo One','Segoe UI',Roboto,sans-serif;font-weight:400;font-size:27px;letter-spacing:4px}
/* ===== Intro video ===== */
#introOv{position:fixed;inset:0;z-index:9999;background:#000;display:flex;align-items:center;justify-content:center;transition:opacity .5s}
#introOv.hide{opacity:0;pointer-events:none}
#introOv video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;background:#000}
/* ===== Comments ===== */
.cm-form{margin:0 16px 18px;padding:16px;border-radius:28px;background:var(--surface);box-shadow:var(--clay-out),var(--clay-in);display:flex;flex-direction:column;gap:10px}
.cm-form input,.cm-form textarea{width:100%;padding:12px 16px;border-radius:18px;border:1px solid var(--border);background:var(--surface-2);color:var(--text);font:inherit;font-size:14px;outline:none;resize:none}
.cm-form input:focus,.cm-form textarea:focus{border-color:var(--accent)}
.cm-form button{padding:12px;border-radius:20px;background:var(--accent-grad);color:#fff;font-weight:800;font-size:14px;box-shadow:var(--clay-sm),var(--clay-in)}
.cm-form button:disabled{opacity:.6}
.cm-list{padding:0 16px 120px;display:flex;flex-direction:column;gap:12px}
.cm-item{padding:14px;border-radius:22px;background:var(--surface);box-shadow:var(--clay-sm),var(--clay-in)}
.cm-head{display:flex;align-items:center;gap:10px;margin-bottom:8px}
.cm-av{width:34px;height:34px;border-radius:50%;background:var(--accent-grad);color:#fff;font-weight:800;display:flex;align-items:center;justify-content:center;flex:0 0 auto}
.cm-name{font-weight:800;font-size:14px}
.cm-time{font-size:11px;color:var(--text-dim)}
.cm-text{font-size:14px;line-height:1.5;white-space:pre-wrap;word-break:break-word}
.cm-reply{margin-top:10px;padding:10px 12px;border-radius:16px;background:var(--surface-2);border-left:3px solid var(--accent-2)}
.cm-reply b{font-size:12px;color:var(--accent-2);display:inline-flex;align-items:center;gap:6px}
.cm-reply .cm-text{margin-top:4px;font-size:13.5px}
.cm-empty{text-align:center;color:var(--text-dim);padding:30px 0;font-size:13px}
.social-links{display:flex;align-items:center;gap:12px;flex:0 0 auto}
.social-btn{width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;text-decoration:none;box-shadow:var(--clay-sm),var(--clay-in);transition:transform .15s}
.social-btn:active{transform:scale(.94)}
.social-wa{background:linear-gradient(145deg,#4ade80,#16a34a)}
.social-tg{background:linear-gradient(145deg,#38bdf8,#0284c7)}
@media(max-width:360px){.social-btn{width:42px;height:42px}.social-links{gap:8px}}
.welcome-overlay{position:fixed;inset:0;z-index:300;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(10,3,5,.66);backdrop-filter:blur(6px)}
.welcome-overlay.show{display:flex}
.welcome-card{position:relative;width:min(92vw,370px);padding:34px 24px 28px;border-radius:36px;text-align:center;background:linear-gradient(145deg,var(--surface-2),var(--surface));box-shadow:var(--clay-out),var(--clay-in);animation:popIn .35s cubic-bezier(.2,.9,.3,1.25)}
@keyframes popIn{from{opacity:0;transform:scale(.85) translateY(14px)}to{opacity:1;transform:none}}
.welcome-close{position:absolute;top:14px;right:14px;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;background:var(--surface-2);color:var(--text);box-shadow:var(--clay-sm),var(--clay-in)}
.welcome-title{font-size:21px;font-weight:900;letter-spacing:.5px}
.welcome-sub{margin-top:8px;font-size:13px;line-height:1.5;color:var(--text-dim)}
.welcome-icons{display:flex;justify-content:center;gap:26px;margin-top:26px}
.social{display:flex;flex-direction:column;align-items:center;gap:10px;font-size:12px;font-weight:700;color:var(--text-dim)}
.social .badge{width:84px;height:84px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;transition:transform .15s;box-shadow:8px 8px 18px rgba(0,0,0,.45),inset 3px 3px 8px rgba(255,255,255,.35),inset -5px -5px 10px rgba(0,0,0,.25)}
.social .badge svg{width:44px;height:44px}
.social.tg .badge{background:linear-gradient(145deg,#3bb6ec,#1d8fc4)}
.social.wa .badge{background:linear-gradient(145deg,#4fe383,#1da851)}
.social:hover .badge{transform:translateY(-3px) scale(1.04)}
.social:active .badge{transform:scale(.94)}
</style>
</head>
<body>
<div id="introOv">
  <video id="introVid" src="https://videotourl.com/videos/1790305558178-11f0f9df-9e8b-436d-bc86-cebfa6d5e176.mp4" playsinline preload="auto" autoplay></video>
</div>
<script>
(function(){
  var ov=document.getElementById("introOv"),v=document.getElementById("introVid"),snd=document.getElementById("introSound"),done=false;
  document.documentElement.style.overflow="hidden";
  function finish(){if(done)return;done=true;try{v.pause();}catch(e){}ov.classList.add("hide");document.documentElement.style.overflow="";setTimeout(function(){ov.remove();},600);}
  v.addEventListener("ended",finish);
  v.addEventListener("error",finish);
  v.muted=false;
  var pr=v.play();
  if(pr&&pr.catch)pr.catch(function(){
    v.muted=true;
    v.play().catch(finish);
    var un=function(){v.muted=false;ov.removeEventListener("touchstart",un);ov.removeEventListener("click",un);};
    ov.addEventListener("touchstart",un);ov.addEventListener("click",un);
  });
})();
</script>

<div id="nativePlayerOverlay">
  <div class="ratio-wrap">
    <div id="nativePlayerLoading"><div class="loading-dots"><span></span><span></span><span></span></div><p>Loading video…</p></div>
    <iframe id="nativePlayerFrame" allowfullscreen allow="autoplay; encrypted-media; picture-in-picture; fullscreen"></iframe>
  </div>
  <div class="lang-bar" id="langBar"></div>
</div>

<header class="app-header">
  <div class="header-row">
    <div class="brand">
      <div class="brand-logo-wrap">
        <img class="brand-logo" id="brandLogo" src="<?= htmlspecialchars($self . '?action=img&url=' . urlencode($logoSrc), ENT_QUOTES) ?>" data-orig="<?= htmlspecialchars($logoSrc, ENT_QUOTES) ?>" referrerpolicy="no-referrer" alt="ANISOL">
        <div class="brand-logo-fallback" id="brandLogoFallback"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="7 4 20 12 7 20 7 4" fill="currentColor"/></svg></div>
      </div>
      <div class="brand-name">ANISOL</div>
    </div>
    <div class="social-links">
      <a class="social-btn social-tg" href="https://t.me/anisolupdate" target="_blank" rel="noopener noreferrer" aria-label="Telegram"><svg viewBox="0 0 24 24" width="26" height="26" fill="#fff" aria-hidden="true"><path d="M16.906 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg></a>
      <a class="social-btn social-wa" href="https://whatsapp.com/channel/0029Vb8wILYAzNbnjdqDro1w" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp"><svg viewBox="0 0 24 24" width="26" height="26" fill="#fff" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.3-.15-1.263-.465-2.403-1.485-.888-.795-1.484-1.77-1.66-2.07-.174-.3-.019-.465.13-.615.136-.135.301-.345.451-.523.146-.181.194-.301.297-.496.1-.21.049-.375-.025-.524-.075-.15-.672-1.62-.922-2.206-.24-.584-.487-.51-.672-.51-.172-.015-.371-.015-.571-.015-.2 0-.523.074-.797.359-.273.3-1.045 1.02-1.045 2.475s1.07 2.865 1.219 3.075c.149.195 2.105 3.195 5.1 4.485.714.3 1.27.48 1.704.629.714.227 1.365.195 1.88.121.574-.091 1.767-.721 2.016-1.426.255-.705.255-1.29.18-1.425-.074-.135-.27-.21-.57-.345m-5.446 7.443h-.016c-1.77 0-3.524-.48-5.055-1.38l-.36-.214-3.75.975 1.005-3.645-.239-.375c-.99-1.576-1.516-3.391-1.516-5.26 0-5.445 4.455-9.885 9.942-9.885 2.654 0 5.145 1.035 7.021 2.91 1.875 1.859 2.909 4.35 2.909 6.99-.004 5.444-4.46 9.885-9.935 9.885M20.52 3.449C18.24 1.245 15.24 0 12.045 0 5.463 0 .104 5.334.101 11.893c0 2.096.549 4.14 1.595 5.945L0 24l6.335-1.652c1.746.943 3.71 1.444 5.71 1.447h.006c6.585 0 11.946-5.336 11.949-11.896 0-3.176-1.24-6.165-3.495-8.411"/></svg></a>
    </div>
  </div>
  <div class="search-box">
    <input type="text" id="globalSearchInput" placeholder="Search anime..." autocomplete="off">
    <button class="search-clear" id="searchClearBtn" aria-label="Clear"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    <span class="search-icon"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg></span>
  </div>
</header>

<main>
  <section class="page active" id="page-home">
    <div class="hero-slider" id="heroSlider">
      <div class="hero-track" id="heroTrack"></div>
      <div class="hero-dots" id="heroDots"></div>
    </div>
    <div class="section" id="recentlyWatchedSection">
      <div class="section-title"><h2>Recently Watched</h2><button id="clearRecentBtn" style="font-size:11.5px;color:var(--danger);font-weight:700">Clear</button></div>
      <div class="h-scroll" id="recentRow"></div>
    </div>
    <div class="section" id="trendingSection">
      <div class="section-title"><h2>New Drop</h2><span class="tag">NEW</span></div>
      <div class="h-scroll" id="trendingRow"></div>
    </div>
    <div class="section">
      <div class="section-title"><h2>Newest Drops</h2><span class="tag">NEW</span></div>
      <div class="h-scroll" id="latestRow"></div>
    </div>
  </section>

  <section class="page" id="page-search">
    <div class="section" style="padding-top:16px"><div class="section-title"><h2>Search Anime</h2></div></div>
    <div class="search-results" id="searchResultsWrap">
      <div class="empty-state"><div class="emoji"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg></div><h3>Search for something to watch</h3><p>Type an anime name above.</p></div>
    </div>
  </section>

  <section class="page" id="page-details">
    <div id="playerSpacer"></div>
    <div id="detailsContent"></div>
  </section>

  <section class="page" id="page-history">
    <div class="section" style="padding-top:16px">
      <div class="section-title"><h2>Watch History</h2><button id="clearHistoryBtn" style="font-size:11.5px;color:var(--danger);font-weight:700">Clear all</button></div>
    </div>
    <div id="historyWrap"></div>
  </section>

  <section class="page" id="page-comments">
    <div class="section" style="padding-top:16px"><div class="section-title"><h2>Comments</h2><span class="tag" id="cmCount">0</span></div></div>
    <form class="cm-form" id="cmForm" autocomplete="off">
      <input type="text" id="cmName" maxlength="30" placeholder="Your name" required>
      <textarea id="cmText" maxlength="500" rows="3" placeholder="Write your comment..." required></textarea>
      <button type="submit" id="cmSend">Post Comment</button>
    </form>
    <div class="cm-list" id="cmList"><div class="cm-empty">Loading comments…</div></div>
  </section>

  <section class="page" id="page-settings">
    <div class="section" style="padding-top:16px"><div class="section-title"><h2>Settings</h2></div></div>
    <div class="settings-list">
      <div class="settings-group">
        <div class="settings-row"><div><div class="label">Autoplay next episode</div><div class="desc">Auto play next episode</div></div><div class="toggle on" id="toggleAutoplay"><div class="knob"></div></div></div>
        <div class="settings-row"><div><div class="label">Save watch history</div><div class="desc">Keep track locally</div></div><div class="toggle on" id="toggleHistory"><div class="knob"></div></div></div>
      </div>
      <div class="settings-group">
        <button class="settings-danger" id="wipeDataBtn" style="width:100%;border-top:1px solid var(--border)">Clear all local data</button>
      </div>
      <div style="text-align:center;color:var(--text-dim);font-size:11px;padding:12px">ANISOL v3.5 · Made with <svg class="ic" viewBox="0 0 24 24" fill="#ef4444" stroke="#ef4444" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M19 14c1.5-1.5 3-3.2 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.8 0-3 .5-4.5 2-1.5-1.5-2.7-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4 3 5.5l7 7z"/></svg></div>
    </div>
  </section>
</main>

<nav class="bottom-nav">
  <button class="nav-btn active" data-page="home"><span class="nav-ic"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/></svg></span>Home</button>
  <button class="nav-btn" data-page="search"><span class="nav-ic"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg></span>Search</button>
  <button class="nav-btn" data-page="history"><span class="nav-ic"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>History</button>
  <button class="nav-btn" data-page="comments"><span class="nav-ic"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L3 21l1.9-5.4A8 8 0 1 1 21 12z"/></svg></span><span>Comments</span></button>
  <button class="nav-btn" data-page="settings"><span class="nav-ic"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg></span>Settings</button>
</nav>

<div class="welcome-overlay" id="welcomeOverlay">
  <div class="welcome-card" role="dialog" aria-modal="true" aria-label="Join ANISOL">
    <button class="welcome-close" id="welcomeClose" aria-label="Close"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    <div class="welcome-title">Join ANISOL</div>
    <p class="welcome-sub">New episodes and updates, straight to your phone.</p>
    <div class="welcome-icons">
      <a class="social tg" href="https://t.me/anisolupdate" target="_blank" rel="noopener noreferrer" aria-label="Telegram"><span class="badge"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M16.906 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg></span>Telegram</a>
      <a class="social wa" href="https://whatsapp.com/channel/0029Vb8wILYAzNbnjdqDro1w" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp"><span class="badge"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.3-.15-1.263-.465-2.403-1.485-.888-.795-1.484-1.77-1.66-2.07-.174-.3-.019-.465.13-.615.136-.135.301-.345.451-.523.146-.181.194-.301.297-.496.1-.21.049-.375-.025-.524-.075-.15-.672-1.62-.922-2.206-.24-.584-.487-.51-.672-.51-.172-.015-.371-.015-.571-.015-.2 0-.523.074-.797.359-.273.3-1.045 1.02-1.045 2.475s1.07 2.865 1.219 3.075c.149.195 2.105 3.195 5.1 4.485.714.3 1.27.48 1.704.629.714.227 1.365.195 1.88.121.574-.091 1.767-.721 2.016-1.426.255-.705.255-1.29.18-1.425-.074-.135-.27-.21-.57-.345m-5.446 7.443h-.016c-1.77 0-3.524-.48-5.055-1.38l-.36-.214-3.75.975 1.005-3.645-.239-.375c-.99-1.576-1.516-3.391-1.516-5.26 0-5.445 4.455-9.885 9.942-9.885 2.654 0 5.145 1.035 7.021 2.91 1.875 1.859 2.909 4.35 2.909 6.99-.004 5.444-4.46 9.885-9.935 9.885M20.52 3.449C18.24 1.245 15.24 0 12.045 0 5.463 0 .104 5.334.101 11.893c0 2.096.549 4.14 1.595 5.945L0 24l6.335-1.652c1.746.943 3.71 1.444 5.71 1.447h.006c6.585 0 11.946-5.336 11.949-11.896 0-3.176-1.24-6.165-3.495-8.411"/></svg></span>WhatsApp</a>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
(function(){
  const img=document.getElementById("brandLogo"),fb=document.getElementById("brandLogoFallback");
  if(img&&fb){
    img.addEventListener("error",()=>{if(!img.dataset.tried&&img.dataset.orig){img.dataset.tried="1";img.src=img.dataset.orig;return;}img.style.display="none";fb.style.display="flex";});
    img.addEventListener("load",()=>{img.style.display="block";fb.style.display="none";});
  }
})();

const SELF = <?= json_encode($self) ?>;
const _s=d=>'<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'+d+'</svg>';
const IC={
  home:_s('<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>'),
  search:_s('<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>'),
  clock:_s('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'),
  settings:_s('<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>'),
  play:_s('<polygon points="7 4 20 12 7 20 7 4" fill="currentColor"/>'),
  x:_s('<path d="M18 6 6 18M6 6l12 12"/>'),
  check:_s('<path d="M20 6 9 17l-5-5"/>'),
  refresh:_s('<path d="M21 12a9 9 0 0 0-15-6.7L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 15 6.7l3-2.7"/><path d="M16 16h5v5"/>'),
  alert:_s('<path d="m21.7 18-8-14a2 2 0 0 0-3.4 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.7-3z"/><path d="M12 9v4M12 17h.01"/>'),
  wifi:_s('<path d="M2 8.8a15 15 0 0 1 20 0M5 12.9a10 10 0 0 1 14 0M8.5 16.4a5 5 0 0 1 7 0M12 20h.01"/>'),
  frown:_s('<circle cx="12" cy="12" r="10"/><path d="M16 16s-1.5-2-4-2-4 2-4 2M9 9h.01M15 9h.01"/>'),
  tv:_s('<rect width="20" height="15" x="2" y="7" rx="2"/><path d="m17 2-5 5-5-5"/>'),
  film:_s('<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M7 3v18M3 7.5h4M3 12h18M3 16.5h4M17 3v18M17 7.5h4M17 16.5h4"/>'),
  globe:_s('<circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>'),
  monitor:_s('<rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8M12 17v4"/>')
};
const STATE = {
  currentPage:"home", heroIndex:0, heroTimer:null, searchTimer:null,
  currentAnime:null, currentSeasons:[], currentSeasonIndex:0,
  currentEpisodeUrl:null, _currentSeason:null, _currentEpList:[],
  _playerHideT:null, _playerFailsafeT:null,
  history:[], settings:{autoplay:true,saveHistory:true,lightTheme:true},
  navStack:["home"]
};

async function fetchJSON(url,tries=2,ms=90000){
  let err;
  for(let i=0;i<tries;i++){
    try{
      const ctrl=new AbortController(),t=setTimeout(()=>ctrl.abort(),ms);
      const r=await fetch(url,{signal:ctrl.signal,cache:url.indexOf("fresh=1")>-1?"no-store":"default"});
      clearTimeout(t);
      if(!r.ok) throw new Error("HTTP "+r.status);
      return JSON.parse(await r.text());
    }catch(e){err=e;if(i<tries-1)await new Promise(r=>setTimeout(r,600));}
  }
  throw err;
}
const API = {
  home:    ()=>fetchJSON(SELF+"?action=home",2,45000),
  search:  q=>fetchJSON(SELF+"?action=search&q="+encodeURIComponent(q),2,45000),
  details: (id,fresh)=>fetchJSON(SELF+"?action=details&id="+encodeURIComponent(id)+(fresh?"&fresh=1":""),2,90000),
  servers: u=>fetchJSON(SELF+"?action=servers&url="+encodeURIComponent(u),2,45000),
};
function imgProxy(u){return (!u||u.startsWith("data:"))?u:SELF+"?action=img&url="+encodeURIComponent(u);}
function showToast(m){const t=document.getElementById("toast");t.textContent=m;t.classList.add("show");clearTimeout(t._t);t._t=setTimeout(()=>t.classList.remove("show"),2200);}
function mkEl(h){const d=document.createElement("div");d.innerHTML=h.trim();return d.firstElementChild;}
function esc(s){return(s||"").toString().replace(/[&<>"']/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[m]));}
function attachImg(img){
  img.addEventListener("load",()=>img.classList.add("loaded"));
  img.addEventListener("error",()=>{
    const orig=img.dataset.orig;
    if(!img.dataset.tried&&orig){img.dataset.tried="1";img.src=orig;return;}
    const light=document.body.classList.contains("light");
    img.onerror=null;
    img.src="data:image/svg+xml;utf8,"+encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="300" height="400"><rect width="100%" height="100%" fill="'+(light?"#f3eaea":"#241014")+'"/><text x="50%" y="50%" fill="#b09194" font-size="16" text-anchor="middle" dy=".3em">No Image</text></svg>');
  });
  if(img.complete&&img.naturalHeight)img.classList.add("loaded");
}
function renderSkeletons(c,n){c.innerHTML="";for(let i=0;i<n;i++)c.appendChild(mkEl('<div class="sk-card"><div class="skeleton sk-thumb"></div><div class="skeleton sk-line w1"></div><div class="skeleton sk-line w2"></div></div>'));}
function renderEmpty(c,emoji,title,desc,btnText,btnFn){
  c.innerHTML="";
  const w=mkEl('<div class="empty-state"><div class="emoji">'+(IC[emoji]||emoji)+'</div><h3>'+esc(title)+'</h3><p>'+esc(desc)+'</p>'+(btnText?'<button>'+esc(btnText)+'</button>':'')+'</div>');
  if(btnText&&btnFn)w.querySelector("button").addEventListener("click",btnFn);
  c.appendChild(w);
}

function navigateTo(p,push){
  if(push!==false&&STATE.currentPage!==p)STATE.navStack.push(p);
  document.querySelectorAll(".page").forEach(x=>x.classList.remove("active"));
  const pg=document.getElementById("page-"+p);if(pg)pg.classList.add("active");
  document.querySelectorAll(".nav-btn").forEach(b=>b.classList.toggle("active",b.dataset.page===p));
  STATE.currentPage=p;
  window.scrollTo({top:0,behavior:"auto"});
  if(p==="history")renderHistoryPage();
  if(p==="comments")initComments();
  if(p!=="details")closePlayer();
}
window.navigateTo=navigateTo;
window.addEventListener("popstate",e=>{
  const o=document.getElementById("nativePlayerOverlay");
  if(o&&o.style.display==="block"){closePlayer();try{history.pushState({page:STATE.currentPage},"","#"+STATE.currentPage);}catch(x){}return;}
  const t=(e.state&&e.state.page)||"home";
  if(e.state&&e.state.stack)STATE.navStack=e.state.stack.slice();
  navigateTo(t,false);
});
document.querySelectorAll(".nav-btn").forEach(b=>b.addEventListener("click",()=>{
  STATE.navStack=[b.dataset.page];
  try{history.pushState({page:b.dataset.page,stack:[b.dataset.page]},"","#"+b.dataset.page);}catch(e){}
  navigateTo(b.dataset.page,false);
}));
try{history.replaceState({page:"home",stack:["home"]},"","#home");}catch(e){}

/* ===== Comments (Firebase Realtime Database) ===== */
const FIREBASE_CONFIG = {
  apiKey: "AIzaSyB5PTJPWBW8eGk3W5wYVu2btKeMveAjy28",
  authDomain: "aahvcv-18018.firebaseapp.com",
  databaseURL: "https://aahvcv-18018-default-rtdb.firebaseio.com",
  projectId: "aahvcv-18018",
  storageBucket: "aahvcv-18018.firebasestorage.app",
  messagingSenderId: "479837325845",
  appId: "1:479837325845:web:266669cfe11a2b47299c1c"
};
let cmStarted=false,cmLast=0;
function cmLoadScript(src){return new Promise((res,rej)=>{const e=document.createElement("script");e.src=src;e.onload=res;e.onerror=rej;document.head.appendChild(e);});}
function cmAgo(ts){if(!ts)return"";const d=Math.max(0,Date.now()-ts),m=Math.floor(d/60000);if(m<1)return"just now";if(m<60)return m+"m ago";const h=Math.floor(m/60);if(h<24)return h+"h ago";const dd=Math.floor(h/24);if(dd<30)return dd+"d ago";return new Date(ts).toLocaleDateString();}
function cmRender(arr){
  const l=document.getElementById("cmList");document.getElementById("cmCount").textContent=arr.length;
  if(!arr.length){l.innerHTML='<div class="cm-empty">No comments yet. Be the first!</div>';return;}
  l.innerHTML=arr.map(c=>'<div class="cm-item"><div class="cm-head"><div class="cm-av">'+esc((c.name||"?").trim().charAt(0).toUpperCase())+'</div><div><div class="cm-name">'+esc(c.name)+'</div><div class="cm-time">'+cmAgo(c.ts)+'</div></div></div><div class="cm-text">'+esc(c.text)+'</div>'+(c.reply&&c.reply.text?'<div class="cm-reply"><b>Admin</b><div class="cm-text">'+esc(c.reply.text)+'</div></div>':'')+'</div>').join("");
}
async function initComments(){
  if(cmStarted)return;cmStarted=true;
  try{
    await cmLoadScript("https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js");
    await cmLoadScript("https://www.gstatic.com/firebasejs/10.12.2/firebase-database-compat.js");
    if(!firebase.apps.length)firebase.initializeApp(FIREBASE_CONFIG);
    firebase.database().ref("comments").orderByChild("ts").limitToLast(200).on("value",snap=>{
      const a=[];snap.forEach(c=>{a.push(Object.assign({id:c.key},c.val()));});a.reverse();cmRender(a);
    },err=>{document.getElementById("cmList").innerHTML='<div class="cm-empty">Could not load comments.</div>';});
  }catch(e){cmStarted=false;document.getElementById("cmList").innerHTML='<div class="cm-empty">Could not load comments. Check your connection.</div>';}
}
(function(){
  const n=document.getElementById("cmName");try{n.value=localStorage.getItem("anisol_cm_name")||"";}catch(e){}
  document.getElementById("cmForm").addEventListener("submit",async ev=>{
    ev.preventDefault();
    const name=n.value.trim().slice(0,30),text=document.getElementById("cmText").value.trim().slice(0,500);
    if(!name||!text)return;
    if(Date.now()-cmLast<15000){showToast("Please wait a few seconds");return;}
    if(!window.firebase||!firebase.apps.length){showToast("Still connecting…");return;}
    const b=document.getElementById("cmSend");b.disabled=true;
    try{
      await firebase.database().ref("comments").push({name:name,text:text,ts:firebase.database.ServerValue.TIMESTAMP});
      cmLast=Date.now();document.getElementById("cmText").value="";try{localStorage.setItem("anisol_cm_name",name);}catch(e){}
      showToast("Comment posted");
    }catch(e){showToast("Could not post comment");}
    b.disabled=false;
  });
})();

async function loadHome(){
  renderSkeletons(document.getElementById("latestRow"),4);
  renderSkeletons(document.getElementById("trendingRow"),4);
  heroSkeleton();
  try{
    const f=await API.home();
    renderHero((f.latest||[]).slice(0,6));
    renderAnimeRow(document.getElementById("latestRow"),f.latest||[]);
    renderAnimeRow(document.getElementById("trendingRow"),f.latest||[]);
  }catch(e){
    renderEmpty(document.getElementById("latestRow").parentElement,"wifi","Couldn't load","Error: "+e.message,"Retry",loadHome);
  }
  renderRecentRow();
}
function heroSkeleton(){document.getElementById("heroTrack").innerHTML='<div class="hero-slide skeleton" style="border-radius:0"></div>';document.getElementById("heroDots").innerHTML="";}
function renderHero(items){
  const t=document.getElementById("heroTrack"),d=document.getElementById("heroDots");
  t.innerHTML="";d.innerHTML="";
  if(!items||!items.length){t.innerHTML='<div class="hero-slide"><div class="hero-info"><h3>No featured anime</h3></div></div>';return;}
  items.forEach((a,i)=>{
    const s=mkEl('<div class="hero-slide"><div class="hero-info">'+(a.smallDetail?'<span class="hero-badge">'+esc(a.smallDetail)+'</span>':'')+'<h3>'+esc(a.title)+'</h3></div></div>');
    if(a.poster){
      const im=new Image();
      im.onload=()=>s.style.backgroundImage="url('"+imgProxy(a.poster)+"')";
      im.onerror=()=>{const im2=new Image();im2.onload=()=>s.style.backgroundImage="url('"+a.poster+"')";im2.src=a.poster;};
      im.src=imgProxy(a.poster);
    }
    s.addEventListener("click",()=>openDetails(a.id,a));
    t.appendChild(s);
    const dot=mkEl('<div class="hero-dot '+(i===0?'active':'')+'"></div>');
    d.appendChild(dot);
  });
  STATE.heroIndex=0;updateHero();startHeroTimer(items.length);
}
function updateHero(){
  document.getElementById("heroTrack").style.transform='translateX(-'+(STATE.heroIndex*100)+'%)';
  document.querySelectorAll(".hero-dot").forEach((d,i)=>d.classList.toggle("active",i===STATE.heroIndex));
}
function startHeroTimer(c){clearInterval(STATE.heroTimer);if(c<=1)return;STATE.heroTimer=setInterval(()=>{STATE.heroIndex=(STATE.heroIndex+1)%c;updateHero();},4500);}
function renderAnimeRow(c,items){
  c.innerHTML="";
  if(!items||!items.length){if(c.parentElement)c.parentElement.style.display="none";return;}
  if(c.parentElement)c.parentElement.style.display="";
  items.forEach(a=>{
    const card=mkEl('<div class="anime-card"><div class="thumb-wrap"><img loading="lazy" src="'+imgProxy(a.poster)+'" data-orig="'+esc(a.poster)+'" alt="'+esc(a.title)+'">'+(a.smallDetail?'<span class="ep-badge">'+esc(a.smallDetail)+'</span>':'')+'</div><div class="card-title">'+esc(a.title)+'</div></div>');
    attachImg(card.querySelector("img"));
    card.addEventListener("click",()=>openDetails(a.id,a));
    c.appendChild(card);
  });
}

const searchInput=document.getElementById("globalSearchInput");
const clearBtn=document.getElementById("searchClearBtn");
searchInput.addEventListener("focus",()=>{
  if(STATE.currentPage!=="search"){STATE.navStack.push("search");try{history.pushState({page:"search",stack:STATE.navStack.slice()},"","#search");}catch(e){}navigateTo("search",false);}
});
searchInput.addEventListener("input",()=>{
  const v=searchInput.value;clearBtn.style.display=v?"block":"none";
  clearTimeout(STATE.searchTimer);STATE.searchTimer=setTimeout(()=>doSearch(v),500);
});
clearBtn.addEventListener("click",()=>{searchInput.value="";clearBtn.style.display="none";doSearch("");});
async function doSearch(q){
  const w=document.getElementById("searchResultsWrap"),t=q.trim();
  if(!t){renderEmpty(w,"search","Search for something to watch","Type an anime name above.");return;}
  w.innerHTML="";
  const sk=mkEl('<div></div>');
  for(let i=0;i<3;i++)sk.appendChild(mkEl('<div class="result-card"><div class="skeleton" style="width:70px;height:96px;border-radius:8px;flex:0 0 auto"></div><div style="flex:1"><div class="skeleton sk-line" style="width:70%"></div><div class="skeleton sk-line" style="width:40%"></div></div></div>'));
  w.appendChild(sk);
  try{
    const d=await API.search(t);const r=d.results||[];w.innerHTML="";
    if(!r.length){renderEmpty(w,"frown","No results",'No anime matched "'+t+'".');return;}
    r.forEach(a=>{
      const c=mkEl('<div class="result-card"><div class="thumb"><img loading="lazy" src="'+imgProxy(a.poster)+'" data-orig="'+esc(a.poster)+'" alt="'+esc(a.title)+'"></div><div class="result-info"><h4>'+esc(a.title)+'</h4>'+(a.smallDetail?'<div class="meta">'+esc(a.smallDetail)+'</div>':'')+'</div></div>');
      attachImg(c.querySelector("img"));
      c.addEventListener("click",()=>openDetails(a.id,a));
      w.appendChild(c);
    });
  }catch(e){w.innerHTML="";renderEmpty(w,"wifi","Search failed","Error: "+e.message,"Retry",()=>doSearch(t));}
}

async function openDetails(id,ki){
  STATE.currentSeasonIndex=0;closePlayer();
  STATE.navStack.push("details");
  try{history.pushState({page:"details",stack:STATE.navStack.slice()},"","#details");}catch(e){}
  navigateTo("details",false);
  const c=document.getElementById("detailsContent");
  c.innerHTML='<div class="details-content"><div class="details-poster-row"><div class="skeleton" style="width:104px;height:148px;border-radius:12px;flex:0 0 auto"></div><div style="flex:1"><div class="skeleton sk-line" style="width:70%;height:20px"></div><div class="skeleton sk-line" style="width:50%"></div></div></div></div>';
  try{
    let d=null;
    try{d=await API.details(id,false);}catch(e){d=null;}
    const needsFresh = !d || d._partial || (!d.title && (!d.seasons || !d.seasons.length));
    if(needsFresh){ try{d=await API.details(id,true);}catch(e){d=null;} }
    if(!d || (!d.title && (!d.seasons || !d.seasons.length))) throw new Error("empty");
    const a={id,title:d.title||(ki&&ki.title)||"",poster:d.poster||(ki&&ki.poster)||"",smallDetail:d.smallDetail||(ki&&ki.smallDetail)||""};
    STATE.currentAnime=a;STATE.currentSeasons=d.seasons||[];
    renderDetails(a,STATE.currentSeasons,d.isMovie);
  }catch(e){
    c.innerHTML="";
    renderEmpty(c,"alert","Couldn't load episodes","Please retry.","Retry",()=>openDetails(id,ki));
  }
}
function renderDetails(a,seasons,isMovie){
  const c=document.getElementById("detailsContent");
  if(isMovie||!seasons||!seasons.length){
    let pUrl=a.id,pTitle=a.title;
    if(seasons&&seasons.length&&seasons[0].episodes&&seasons[0].episodes.length){pUrl=seasons[0].episodes[0].url;pTitle=seasons[0].episodes[0].title||a.title;}
    c.innerHTML='<div class="movie-hero"><div class="movie-poster-lg"><img src="'+imgProxy(a.poster)+'" data-orig="'+esc(a.poster)+'" alt="'+esc(a.title)+'"></div><h1 class="movie-title-lg">'+esc(a.title)+'</h1><p class="movie-sub">'+IC.film+' Movie · Full video</p><button class="play-btn-lg" id="playMovieBtn"><span>'+IC.play+'</span> Play Now</button></div>';
    attachImg(c.querySelector(".movie-poster-lg img"));
    document.getElementById("playMovieBtn").addEventListener("click",()=>openPlayer(a,{label:"Movie",number:1},[{number:1,url:pUrl,title:pTitle}],{number:1,url:pUrl,title:pTitle}));
    return;
  }
  c.innerHTML='<div class="details-content"><div class="details-poster-row"><div class="details-poster"><img src="'+imgProxy(a.poster)+'" data-orig="'+esc(a.poster)+'" alt="'+esc(a.title)+'"></div><div class="details-title-block"><h1>'+esc(a.title)+'</h1>'+(a.smallDetail?'<span class="status-pill">'+esc(a.smallDetail)+'</span>':'')+'</div></div><div class="section-title" style="margin-top:20px"><h2>Episodes</h2></div><div class="season-selector" id="seasonSelector"></div><div class="episode-list" id="episodeListWrap"></div></div>';
  attachImg(c.querySelector(".details-poster img"));
  const ss=document.getElementById("seasonSelector"),ew=document.getElementById("episodeListWrap");
  let si=seasons.length-1;
  function sel(i){si=i;ss.querySelectorAll(".season-chip").forEach((x,ix)=>x.classList.toggle("active",ix===i));renderEpList(ew,a,seasons[i]);}
  seasons.forEach((s,i)=>{const ch=mkEl('<button class="season-chip '+(i===si?"active":"")+'">'+esc(s.label)+'</button>');ch.addEventListener("click",()=>sel(i));ss.appendChild(ch);});
  sel(si);
}
function renderEpList(w,a,s){
  w.innerHTML="";const eps=s.episodes||[];
  if(!eps.length){renderEmpty(w,"tv","No episodes yet","Not available right now.");return;}
  eps.forEach(ep=>{
    const watched=STATE.history.some(h=>h.episodeUrl===ep.url);
    const it=mkEl('<div class="episode-item" data-url="'+esc(ep.url)+'">'+ep.number+(watched?'<span class="watched-tick">'+IC.check+'</span>':'')+'</div>');
    it.addEventListener("click",()=>openPlayer(a,s,eps,ep));
    w.appendChild(it);
  });
}

function updateSpacerHeight(){
  const o=document.getElementById("nativePlayerOverlay");
  if(!o||o.style.display!=="block")return;
  requestAnimationFrame(()=>{
    const h=o.getBoundingClientRect().height;
    document.documentElement.style.setProperty("--player-h",h+"px");
  });
}
window.addEventListener("resize",updateSpacerHeight);

function renderLangBar(tracks, current){
  const bar=document.getElementById("langBar");
  if(!tracks || tracks.length<1){ bar.classList.remove("show"); bar.innerHTML=""; updateSpacerHeight(); return; }
  bar.innerHTML="";
  const label=document.createElement("div");
  label.className="lang-label";
  label.innerHTML=IC.globe+" Language";
  bar.appendChild(label);
  tracks.forEach((t,i)=>{
    const b=document.createElement("button");
    b.className="lang-chip"+(i===current?" active":"");
    let lbl=t.name||t.lang||("Track "+(i+1));
    const ll=(t.lang||"").toLowerCase();
    const lb=lbl.toLowerCase();
    b.textContent=lbl;
    b.addEventListener("click",()=>{
      try{
        const frame=document.getElementById("nativePlayerFrame");
        frame.contentWindow.postMessage({type:"anisol_setAudio", index:i}, "*");
      }catch(err){}
      renderLangBar(tracks, i);
      showToast("Language: "+lbl);
    });
    bar.appendChild(b);
  });
  bar.classList.add("show");
  updateSpacerHeight();
}

async function openPlayer(a,s,list,ep){
  STATE.currentAnime=a; STATE._currentSeason=s; STATE._currentEpList=list||[];
  STATE.currentEpisodeUrl=ep.url;

  const o=document.getElementById("nativePlayerOverlay");
  const f=document.getElementById("nativePlayerFrame");
  const l=document.getElementById("nativePlayerLoading");

  if(STATE._playerHideT){clearTimeout(STATE._playerHideT);STATE._playerHideT=null;}
  if(STATE._playerFailsafeT){clearTimeout(STATE._playerFailsafeT);STATE._playerFailsafeT=null;}

  l.classList.remove("hide");
  try{f.src="about:blank";}catch(e){}
  o.style.display="block";
  renderLangBar([], 0);

  let servers=[];
  try{
    const res=await API.servers(ep.url);
    servers=res.servers||[];
  }catch(e){ servers=[]; }

  const withEmbed=servers.filter(sv=>sv.embed&&sv.embed.length>0);
  if(withEmbed.length>0) servers=withEmbed;

  let pick=0;
  for(let i=0;i<servers.length;i++){ if(servers[i].label&&servers[i].label.toLowerCase().indexOf('multi')>-1){pick=i;break;} }

  let src=SELF+"?action=player&url="+encodeURIComponent(ep.url);
  if(servers.length>0 && servers[pick].embed) src+="&embed="+encodeURIComponent(servers[pick].embed);
  f.src=src;

  let done=false;
  const hide=()=>{if(done)return;done=true;l.classList.add("hide");};
  STATE._playerHideT=setTimeout(hide,10000);
  STATE._playerFailsafeT=setTimeout(hide,20000);

  updateSpacerHeight();
  requestAnimationFrame(()=>{
    document.body.classList.add("player-open");
    window.scrollTo({top:0,behavior:"auto"});
  });
  try{history.pushState({page:"player"},"","#player");}catch(e){}
  saveHistory(a,s,ep);
  showToast("Playing "+(ep.title||"Episode "+ep.number));
  document.querySelectorAll(".episode-item").forEach(n=>n.classList.remove("now-playing"));
  try{const x=document.querySelector('.episode-item[data-url="'+CSS.escape(ep.url)+'"]');if(x)x.classList.add("now-playing");}catch(e){}
}

function closePlayer(){
  const o=document.getElementById("nativePlayerOverlay");
  const f=document.getElementById("nativePlayerFrame");
  const l=document.getElementById("nativePlayerLoading");
  if(!o||o.style.display!=="block")return;
  if(STATE._playerHideT){clearTimeout(STATE._playerHideT);STATE._playerHideT=null;}
  if(STATE._playerFailsafeT){clearTimeout(STATE._playerFailsafeT);STATE._playerFailsafeT=null;}
  try{f.src="about:blank";}catch(e){}
  o.style.display="none";
  l.classList.add("hide");
  document.body.classList.remove("player-open");
  document.documentElement.style.setProperty("--player-h","0px");
  document.querySelectorAll(".episode-item.now-playing").forEach(n=>n.classList.remove("now-playing"));
  const lb=document.getElementById("langBar");
  if(lb){lb.innerHTML="";lb.classList.remove("show");}
}

window.addEventListener("message",e=>{
  if(!e.data)return;
  if(e.data.type==="anisol_ready"){
    const l=document.getElementById("nativePlayerLoading");
    if(l)l.classList.add("hide");
    if(STATE._playerHideT){clearTimeout(STATE._playerHideT);STATE._playerHideT=null;}
    if(STATE._playerFailsafeT){clearTimeout(STATE._playerFailsafeT);STATE._playerFailsafeT=null;}
  }
  if(e.data.type==="anisol_audios"){
    const tracks = Array.isArray(e.data.tracks) ? e.data.tracks : [];
    const cur = typeof e.data.current === "number" ? e.data.current : 0;
    renderLangBar(tracks, cur);
  }
  if(e.data.type==="anisol_next"){
    if(!STATE.settings.autoplay)return;
    const eps=STATE._currentEpList||[];
    const idx=eps.findIndex(x=>x.url===STATE.currentEpisodeUrl);
    if(idx>=0&&idx+1<eps.length){openPlayer(STATE.currentAnime,STATE._currentSeason,eps,eps[idx+1]);}
    else{showToast("Last episode reached");}
  }
});

const HK="anistream_hist";
function goodPoster(u){if(!u)return false;const l=u.toLowerCase();return!['crunchyroll','netflix','disney','hulu','logo','icon','avatar','placeholder','sprite'].some(b=>l.includes(b));}
function loadHistStorage(){try{const r=localStorage.getItem(HK);const raw=r?JSON.parse(r):[];STATE.history=raw.filter(x=>goodPoster(x.poster));if(STATE.history.length!==raw.length)saveHistStorage();}catch(e){STATE.history=[];}}
function saveHistStorage(){try{localStorage.setItem(HK,JSON.stringify(STATE.history));}catch(e){}}
function saveHistory(a,s,ep){
  if(!STATE.settings.saveHistory||!goodPoster(a.poster))return;
  STATE.history=STATE.history.filter(h=>h.animeId!==a.id);
  STATE.history.unshift({animeId:a.id,animeTitle:a.title,poster:a.poster,seasonLabel:s.label,episodeUrl:ep.url,episodeNumber:ep.number,watchedAt:Date.now()});
  STATE.history=STATE.history.slice(0,30);
  saveHistStorage();renderRecentRow();
}
function renderRecentRow(){
  const r=document.getElementById("recentRow"),sec=document.getElementById("recentlyWatchedSection");
  if(!STATE.history.length){sec.style.display="none";return;}
  sec.style.display="block";r.innerHTML="";
  STATE.history.slice(0,10).forEach(it=>{
    const c=mkEl('<div class="anime-card"><div class="thumb-wrap"><img loading="lazy" src="'+imgProxy(it.poster)+'" data-orig="'+esc(it.poster)+'" alt="'+esc(it.animeTitle)+'"><span class="ep-badge">EP '+it.episodeNumber+'</span></div><div class="card-title">'+esc(it.animeTitle)+'</div><div class="card-sub">'+esc(it.seasonLabel)+'</div></div>');
    attachImg(c.querySelector("img"));
    c.addEventListener("click",()=>openDetails(it.animeId,{title:it.animeTitle,poster:it.poster}));
    r.appendChild(c);
  });
}
function renderHistoryPage(){
  const w=document.getElementById("historyWrap");
  if(!STATE.history.length){renderEmpty(w,"clock","No watch history","Episodes you watch will appear here.","Browse Anime",()=>navigateTo("home"));return;}
  const g=mkEl('<div class="history-grid"></div>');
  STATE.history.forEach(it=>{
    const c=mkEl('<div class="history-card"><div class="thumb-wrap"><img loading="lazy" src="'+imgProxy(it.poster)+'" data-orig="'+esc(it.poster)+'" alt="'+esc(it.animeTitle)+'"><button class="remove-btn">'+IC.x+'</button></div><div class="card-title">'+esc(it.animeTitle)+'</div><div class="card-sub">'+esc(it.seasonLabel)+' · Ep '+it.episodeNumber+'</div></div>');
    attachImg(c.querySelector("img"));
    c.addEventListener("click",e=>{if(e.target.classList.contains("remove-btn"))return;openDetails(it.animeId,{title:it.animeTitle,poster:it.poster});});
    c.querySelector(".remove-btn").addEventListener("click",e=>{e.stopPropagation();STATE.history=STATE.history.filter(h=>h.episodeUrl!==it.episodeUrl);saveHistStorage();renderHistoryPage();renderRecentRow();showToast("Removed");});
    g.appendChild(c);
  });
  w.innerHTML="";w.appendChild(g);
}
document.getElementById("clearHistoryBtn").addEventListener("click",()=>{if(!STATE.history.length)return;STATE.history=[];saveHistStorage();renderHistoryPage();renderRecentRow();showToast("History cleared");});
document.getElementById("clearRecentBtn").addEventListener("click",()=>{STATE.history=[];saveHistStorage();renderRecentRow();renderHistoryPage();showToast("Cleared");});

const SK="anistream_settings";
function applyTheme(){
  document.body.classList.toggle("light",!!STATE.settings.lightTheme);
  const m=document.querySelector('meta[name="theme-color"]');
  if(m)m.setAttribute("content",STATE.settings.lightTheme?"#f4dede":"#240a0f");
}
function loadSettings(){
  try{const r=localStorage.getItem(SK);if(r)STATE.settings=Object.assign({},STATE.settings,JSON.parse(r));}catch(e){}
  STATE.settings.lightTheme=true;
  document.getElementById("toggleAutoplay").classList.toggle("on",STATE.settings.autoplay);
  document.getElementById("toggleHistory").classList.toggle("on",STATE.settings.saveHistory);
  applyTheme();
}
function saveSettings(){try{localStorage.setItem(SK,JSON.stringify(STATE.settings));}catch(e){}}
function bindToggle(id,k,after){
  const t=document.getElementById(id);
  t.addEventListener("click",()=>{STATE.settings[k]=!STATE.settings[k];t.classList.toggle("on",STATE.settings[k]);saveSettings();if(after)after();showToast(STATE.settings[k]?"Enabled":"Disabled");});
}
bindToggle("toggleAutoplay","autoplay");
bindToggle("toggleHistory","saveHistory");
document.getElementById("wipeDataBtn").addEventListener("click",()=>{
  if(!confirm("Clear all local data?"))return;
  localStorage.removeItem(HK);localStorage.removeItem(SK);
  STATE.history=[];STATE.settings={autoplay:true,saveHistory:true,lightTheme:false};
  loadSettings();renderRecentRow();showToast("Data cleared");
});
document.addEventListener("visibilitychange",()=>{if(document.hidden)clearInterval(STATE.heroTimer);});

loadHistStorage();
loadSettings();
loadHome();
renderRecentRow();

(function(){
  const K="anisol_welcome_seen",ov=document.getElementById("welcomeOverlay");
  function closeW(){ov.classList.remove("show");}
  document.getElementById("welcomeClose").addEventListener("click",closeW);
  ov.addEventListener("click",e=>{if(e.target===ov)closeW();});
  ov.querySelectorAll("a.social").forEach(a=>a.addEventListener("click",closeW));
  setTimeout(()=>ov.classList.add("show"),700);
})();
</script>
</body>
</html><?php
}
