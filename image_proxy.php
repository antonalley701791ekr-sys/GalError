<?php
/**
 * 图片反向代理
 * 用于加载远程图片（VNDB CDN / 自定义 URL），解决 Canvas CORS 限制
 * 请求: GET /image_proxy.php?url=https://s.vndb.org/cv/xxx.jpg
 */

// 不加载 includes/config.php：避免每张图片请求触发数据库与迁移初始化。
if (!defined('BASE_PATH')) {
    define('BASE_PATH', __DIR__ . '/');
}
if (!defined('UPLOAD_PATH')) {
    define('UPLOAD_PATH', 'data/');
}

function normalizeContentType($value)
{
    $contentType = trim((string)$value);
    if ($contentType !== '' && strpos($contentType, ';') !== false) {
        $contentType = trim(explode(';', $contentType)[0]);
    }
    return $contentType;
}

function clientCacheFresh($etag, $lastModified)
{
    $ifNoneMatch = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
        return true;
    }

    $ifModifiedSince = trim((string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));
    if ($ifModifiedSince !== '') {
        $since = strtotime($ifModifiedSince);
        if ($since !== false && $lastModified <= $since) {
            return true;
        }
    }

    return false;
}

function sendCachedImage($data, $contentType, $mtime, $isStale, $terminate = true)
{
    $etag = '"' . md5((string)$data) . '"';
    $lastModified = gmdate('D, d M Y H:i:s', (int)$mtime) . ' GMT';

    if (clientCacheFresh($etag, (int)$mtime)) {
        http_response_code(304);
        header('ETag: ' . $etag);
        header('Last-Modified: ' . $lastModified);
        header('Cache-Control: public, max-age=86400, immutable');
        header('Vary: Accept-Encoding');
        if ($terminate) {
            exit;
        }
        return;
    }

    header('Content-Type: ' . normalizeContentType($contentType));
    header('Content-Length: ' . strlen((string)$data));
    header('ETag: ' . $etag);
    header('Last-Modified: ' . $lastModified);
    header('Cache-Control: public, max-age=86400, immutable');
    header('Vary: Accept-Encoding');
    header('Access-Control-Allow-Origin: *');
    if ($isStale) {
        header('Warning: 110 - "Response is stale"');
    }
    echo $data;

    if ($terminate) {
        exit;
    }
}

function fetchRemoteImagePayload($url, $maxSize)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'GalError-ImageProxy/1.3',
        CURLOPT_HTTPHEADER => ['Accept: image/*'],
        CURLOPT_MAXFILESIZE => $maxSize,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = normalizeContentType(curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response !== false && $httpCode === 200
        && $contentType !== ''
        && preg_match('/^image\/(jpeg|png|gif|webp|svg\+xml|svg)/i', $contentType)
        && strlen($response) <= $maxSize) {
        return [
            'data' => $response,
            'content_type' => $contentType,
            'error' => '',
        ];
    }

    return [
        'data' => null,
        'content_type' => '',
        'error' => $curlError ?: ('HTTP ' . $httpCode),
    ];
}

// 仅允许 GET 请求
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$url = trim((string)($_GET['url'] ?? ''));
if ($url === '') {
    http_response_code(400);
    exit('Missing url parameter');
}

if (!preg_match('/^https?:\/\//i', $url)) {
    http_response_code(400);
    exit('Only HTTP/HTTPS URLs are allowed');
}

$parsedUrl = parse_url($url);
$host = trim((string)($parsedUrl['host'] ?? ''));
if ($host === '') {
    http_response_code(400);
    exit('Invalid URL');
}

// 防 SSRF：解析所有 IPv4 地址并拦截内网网段
$resolvedIps = @gethostbynamel($host);
if (is_array($resolvedIps) && !empty($resolvedIps)) {
    $privateRanges = [
        ['127.0.0.0', '127.255.255.255'],
        ['10.0.0.0', '10.255.255.255'],
        ['172.16.0.0', '172.31.255.255'],
        ['192.168.0.0', '192.168.255.255'],
        ['169.254.0.0', '169.254.255.255'],
        ['0.0.0.0', '0.255.255.255'],
    ];
    foreach ($resolvedIps as $ip) {
        $longIp = ip2long($ip);
        if ($longIp === false) {
            continue;
        }
        foreach ($privateRanges as $range) {
            $start = ip2long($range[0]);
            $end = ip2long($range[1]);
            if ($longIp >= $start && $longIp <= $end) {
                http_response_code(403);
                exit('Access to private networks is forbidden');
            }
        }
    }
}

$maxSize = 5 * 1024 * 1024; // 5MB
$cacheDir = BASE_PATH . UPLOAD_PATH . 'cache/';
$cacheExpiry = 7 * 24 * 3600;
$cacheKey = md5($url);
$cacheFile = $cacheDir . $cacheKey;
$cacheMeta = $cacheDir . $cacheKey . '.meta';
$cacheLock = $cacheDir . $cacheKey . '.lock';

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

$hasCache = file_exists($cacheFile) && file_exists($cacheMeta);
$isFreshCache = $hasCache && ((time() - filemtime($cacheFile)) < $cacheExpiry);

// 命中缓存即优先返回；过期缓存走 stale-while-revalidate
if ($hasCache) {
    $cachedType = @file_get_contents($cacheMeta);
    $cachedData = @file_get_contents($cacheFile);
    if ($cachedType !== false && $cachedData !== false) {
        $cachedMtime = (int)filemtime($cacheFile);

        if ($isFreshCache) {
            sendCachedImage($cachedData, $cachedType, $cachedMtime, false);
        }

        sendCachedImage($cachedData, $cachedType, $cachedMtime, true, false);

        $refreshResult = fetchRemoteImagePayload($url, $maxSize);
        if (!empty($refreshResult['data']) && !empty($refreshResult['content_type'])) {
            @file_put_contents($cacheFile, $refreshResult['data']);
            @file_put_contents($cacheMeta, $refreshResult['content_type']);
        }

        exit;
    }
}

// 防击穿：同一 URL 回源时加短锁，其他请求短暂等待缓存产出
$lockFp = @fopen($cacheLock, 'c');
$hasLock = $lockFp ? @flock($lockFp, LOCK_EX | LOCK_NB) : false;
if (!$hasLock) {
    $waitUntil = microtime(true) + 2.5;
    do {
        usleep(120000);
        if (file_exists($cacheFile) && file_exists($cacheMeta)) {
            $cachedType = @file_get_contents($cacheMeta);
            $cachedData = @file_get_contents($cacheFile);
            if ($cachedType !== false && $cachedData !== false) {
                $isStale = ((time() - filemtime($cacheFile)) >= $cacheExpiry);
                sendCachedImage($cachedData, $cachedType, filemtime($cacheFile), $isStale);
            }
        }
    } while (microtime(true) < $waitUntil);
}

$remote = fetchRemoteImagePayload($url, $maxSize);
if (!empty($remote['data']) && !empty($remote['content_type'])) {
    @file_put_contents($cacheFile, $remote['data']);
    @file_put_contents($cacheMeta, $remote['content_type']);

    if ($hasLock && $lockFp) {
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
    }

    sendCachedImage($remote['data'], $remote['content_type'], time(), false);
}

if ($hasLock && $lockFp) {
    @flock($lockFp, LOCK_UN);
    @fclose($lockFp);
}

// 回源失败时尽量返回旧缓存（stale-if-error）
if ($hasCache) {
    $cachedType = @file_get_contents($cacheMeta);
    $cachedData = @file_get_contents($cacheFile);
    if ($cachedType !== false && $cachedData !== false) {
        sendCachedImage($cachedData, $cachedType, filemtime($cacheFile), true);
    }
}

http_response_code(502);
header('Content-Type: application/json');
echo json_encode(['error' => 'Failed to fetch image: ' . ($remote['error'] ?? 'unknown')]);
