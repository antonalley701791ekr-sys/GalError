<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Parsedown.php';

function markdown_get_link_boundary_pattern()
{
    return "(?=$|[\\s<\\)\\]\\}>\"'，。！？；：、]|[\x{4E00}-\x{9FFF}])";
}

function markdown_decode_url_if_needed($url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }

    if (preg_match('/^https?:\/\//i', $url)) {
        return $url;
    }

    if (preg_match('/^https?%3A\/\//i', $url)) {
        $decoded = rawurldecode($url);
        if (preg_match('/^https?:\/\//i', $decoded)) {
            return $decoded;
        }
    }

    return $url;
}

function markdown_extract_embedded_image_url($url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }

    if (preg_match('/!\[[^\]]*\]\(([^)]+)\)/u', $url, $matches)) {
        return markdown_decode_url_if_needed($matches[1]);
    }

    if (preg_match('/\/!\[[^\]]*\]\(([^)]+)\)/u', $url, $matches)) {
        return markdown_decode_url_if_needed($matches[1]);
    }

    return '';
}

function markdown_trim_trailing_url_punctuation($url)
{
    $url = (string) $url;
    if ($url === '') {
        return ['', ''];
    }

    $trailing = '';
    while ($url !== '') {
        $lastChar = mb_substr($url, -1, 1, 'UTF-8');
        if (!preg_match('/[\\.,!?:;，。！？；：、]/u', $lastChar)) {
            break;
        }
        $trailing = $lastChar . $trailing;
        $url = mb_substr($url, 0, mb_strlen($url, 'UTF-8') - 1, 'UTF-8');
    }

    return [$url, $trailing];
}

function markdown_split_url_suffix($url)
{
    $url = markdown_decode_url_if_needed($url);
    if ($url === '') {
        return ['', ''];
    }

    list($trimmedUrl, $suffix) = markdown_trim_trailing_url_punctuation($url);

    while ($trimmedUrl !== '') {
        $lastChar = substr($trimmedUrl, -1);
        if (!in_array($lastChar, [')', ']', '}'], true)) {
            break;
        }

        $openingChar = $lastChar === ')' ? '(' : ($lastChar === ']' ? '[' : '{');
        if (substr_count($trimmedUrl, $openingChar) >= substr_count($trimmedUrl, $lastChar)) {
            break;
        }

        $suffix = $lastChar . $suffix;
        $trimmedUrl = substr($trimmedUrl, 0, -1);
    }

    return [$trimmedUrl, $suffix];
}

function markdown_get_image_probe_cache_paths($url)
{
    $cacheDir = BASE_PATH . UPLOAD_PATH . 'cache/';
    $cacheKey = md5('md-probe:' . $url);

    return [
        'dir' => $cacheDir,
        'meta' => $cacheDir . $cacheKey . '.image-probe-meta',
        'ttl' => 7 * 24 * 3600,
    ];
}

function markdown_read_cached_image_probe($url)
{
    $paths = markdown_get_image_probe_cache_paths($url);
    if (!file_exists($paths['meta'])) {
        return null;
    }

    if ((time() - filemtime($paths['meta'])) >= $paths['ttl']) {
        return null;
    }

    $raw = @file_get_contents($paths['meta']);
    if ($raw === false || $raw === '') {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['is_image'])) {
        return null;
    }

    return !empty($data['is_image']);
}

function markdown_write_cached_image_probe($url, $isImage)
{
    $paths = markdown_get_image_probe_cache_paths($url);
    if (!is_dir($paths['dir'])) {
        @mkdir($paths['dir'], 0755, true);
    }

    @file_put_contents($paths['meta'], json_encode([
        'is_image' => $isImage ? 1 : 0,
        'checked_at' => time(),
    ]));
}

function markdown_probe_remote_image_url($url)
{
    $url = markdown_decode_url_if_needed($url);
    if ($url === '' || !preg_match('/^https?:\/\//i', $url)) {
        return false;
    }

    $cached = markdown_read_cached_image_probe($url);
    if ($cached !== null) {
        return $cached;
    }

    $isImage = false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'GalError-MarkdownProbe/1.0',
        CURLOPT_HTTPHEADER => ['Accept: image/*,*/*;q=0.8'],
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 400 && $contentType && preg_match('/^image\/(jpeg|png|gif|webp|svg\+xml|svg)/i', $contentType)) {
        $isImage = true;
    }

    markdown_write_cached_image_probe($url, $isImage);
    return $isImage;
}

function markdown_proxy_image_url($url)
{
    $url = markdown_decode_url_if_needed($url);
    if ($url === '') {
        return '';
    }

    if (preg_match('#^(?:https?:)?//#i', $url)) {
        if (stripos($url, '//') === 0) {
            $url = 'https:' . $url;
        }
        return '/image_proxy.php?url=' . rawurlencode($url);
    }

    return $url;
}

function markdown_is_renderable_image_url($url)
{
    $url = markdown_decode_url_if_needed($url);
    if ($url === '') {
        return false;
    }

    if (preg_match('/^(?:https?:)?\/\/[^\s]+\.(?:jpg|jpeg|png|gif|webp|svg)(?:\?[^\s]*)?(?:#[^\s]*)?$/i', $url)) {
        return true;
    }

    return markdown_probe_remote_image_url($url);
}

function markdown_extract_embedded_external_url($url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }

    if (preg_match('/\[(https?%3A\/\/[^\]]+)\]/i', $url, $matches)) {
        $decoded = rawurldecode($matches[1]);
        if (preg_match('/^https?:\/\//i', $decoded)) {
            return $decoded;
        }
    }

    if (preg_match('/\[(https?:\/\/[^\]]+)\]/i', $url, $matches)) {
        if (preg_match('/^https?:\/\//i', $matches[1])) {
            return $matches[1];
        }
    }

    return '';
}

function markdown_normalize_link_href($url)
{
    $url = markdown_decode_url_if_needed($url);
    if ($url === '') {
        return '';
    }

    $embeddedExternalUrl = markdown_extract_embedded_external_url($url);
    if ($embeddedExternalUrl !== '') {
        return $embeddedExternalUrl;
    }

    $embeddedImageUrl = markdown_extract_embedded_image_url($url);
    if ($embeddedImageUrl !== '' && markdown_is_renderable_image_url($embeddedImageUrl)) {
        return markdown_proxy_image_url($embeddedImageUrl);
    }

    if (markdown_is_renderable_image_url($url)) {
        return markdown_proxy_image_url($url);
    }

    return $url;
}

function markdown_normalize_plain_image_urls($markdown)
{
    $markdown = (string) ($markdown ?? '');
    if ($markdown === '') {
        return '';
    }

    $boundaryPattern = markdown_get_link_boundary_pattern();

    return preg_replace_callback(
        '/(^|[\s>\(\[\{])((?:https?:\/\/|https?%3A\/\/)[^\s<]+?)' . $boundaryPattern . '/iu',
        function ($matches) {
            $prefix = $matches[1];
            list($url, $suffix) = markdown_split_url_suffix($matches[2]);

            if ($url === '' || !markdown_is_renderable_image_url($url)) {
                return $matches[0];
            }

            return $prefix . '![](' . $url . ')' . $suffix;
        },
        $markdown
    );
}

function markdown_normalize_plain_links($markdown)
{
    $markdown = (string) ($markdown ?? '');
    if ($markdown === '') {
        return '';
    }

    $boundaryPattern = markdown_get_link_boundary_pattern();

    return preg_replace_callback(
        '/(^|[\s>\(\[\{])((?:https?:\/\/|https?%3A\/\/|www\.)[^\s<]+?)' . $boundaryPattern . '/iu',
        function ($matches) {
            $prefix = $matches[1];
            list($url, $suffix) = markdown_split_url_suffix($matches[2]);

            if ($url === '') {
                return $matches[0];
            }

            if (strpos($url, '@') !== false || stripos($url, 'javascript:') === 0) {
                return $matches[0];
            }

            $href = $url;
            if (stripos($href, 'www.') === 0) {
                $href = 'https://' . $href;
            }

            if (!preg_match('/^https?:\/\//i', $href)) {
                return $matches[0];
            }

            return $prefix . '[' . $url . '](' . $href . ')' . $suffix;
        },
        $markdown
    );
}

function markdown_normalize_tables($markdown)
{
    $markdown = (string) ($markdown ?? '');
    if ($markdown === '') {
        return '';
    }

    $lines = preg_split('/\r\n|\r|\n/', $markdown);
    if (!is_array($lines) || !$lines) {
        return $markdown;
    }

    $result = [];
    $lineCount = count($lines);
    $i = 0;

    while ($i < $lineCount) {
        $headerLine = $lines[$i];
        $separatorLine = ($i + 1 < $lineCount) ? $lines[$i + 1] : '';

        if (!markdown_is_table_row($headerLine) || !markdown_is_table_separator($separatorLine)) {
            $result[] = $headerLine;
            $i++;
            continue;
        }

        $headerCells = markdown_split_table_cells($headerLine);
        $separatorCells = markdown_split_table_cells($separatorLine);
        $columnCount = max(count($headerCells), count($separatorCells));

        if ($columnCount <= 0) {
            $result[] = $headerLine;
            $i++;
            continue;
        }

        $result[] = markdown_build_table_row(markdown_pad_table_cells($headerCells, $columnCount));
        $result[] = markdown_build_table_separator_row(markdown_pad_separator_cells($separatorCells, $columnCount));

        $j = $i + 2;
        while ($j < $lineCount && markdown_is_table_row($lines[$j])) {
            $rowCells = markdown_split_table_cells($lines[$j]);
            $result[] = markdown_build_table_row(markdown_pad_table_cells($rowCells, $columnCount));
            $j++;
        }

        $i = $j;
    }

    return implode("\n", $result);
}

function markdown_is_table_row($line)
{
    $line = trim((string) $line);
    if ($line === '') {
        return false;
    }

    return substr_count($line, '|') >= 2;
}

function markdown_is_table_separator($line)
{
    $line = trim((string) $line);
    if ($line === '' || substr_count($line, '|') < 2) {
        return false;
    }

    $cells = markdown_split_table_cells($line);
    if (empty($cells)) {
        return false;
    }

    $hasValidSeparatorCell = false;
    foreach ($cells as $cell) {
        if (preg_match('/^:?-{3,}:?$/', trim($cell))) {
            $hasValidSeparatorCell = true;
            break;
        }
    }

    return $hasValidSeparatorCell;
}

function markdown_split_table_cells($line)
{
    $line = trim((string) $line);
    $line = preg_replace('/^\|\s*/', '', $line);
    $line = preg_replace('/\s*\|$/', '', $line);

    if ($line === '') {
        return [];
    }

    $cells = preg_split('/\s*\|\s*/', $line);
    return is_array($cells) ? array_map('trim', $cells) : [];
}

function markdown_pad_table_cells(array $cells, $targetCount)
{
    $cells = array_values($cells);
    if (count($cells) > $targetCount) {
        $cells = array_slice($cells, 0, $targetCount);
    }

    while (count($cells) < $targetCount) {
        $cells[] = '';
    }

    return $cells;
}

function markdown_pad_separator_cells(array $cells, $targetCount)
{
    $cells = markdown_pad_table_cells($cells, $targetCount);

    foreach ($cells as $idx => $cell) {
        $cell = trim((string) $cell);
        if (!preg_match('/^:?-{3,}:?$/', $cell)) {
            $cells[$idx] = '---';
        }
    }

    return $cells;
}

function markdown_build_table_row(array $cells)
{
    return '| ' . implode(' | ', $cells) . ' |';
}

function markdown_build_table_separator_row(array $cells)
{
    return '| ' . implode(' | ', $cells) . ' |';
}

function markdown_escape_html($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function markdown_render_table_html(array $rows)
{
    if (count($rows) < 2) {
        return '';
    }

    $headerCells = markdown_split_table_cells($rows[0]);
    $separatorCells = markdown_split_table_cells($rows[1]);
    $columnCount = max(count($headerCells), count($separatorCells));

    if ($columnCount <= 0) {
        return '';
    }

    $headerCells = markdown_pad_table_cells($headerCells, $columnCount);

    $html = '<table><thead><tr>';
    foreach ($headerCells as $cell) {
        $html .= '<th>' . markdown_escape_html($cell) . '</th>';
    }
    $html .= '</tr></thead><tbody>';

    for ($i = 2; $i < count($rows); $i++) {
        $rowCells = markdown_pad_table_cells(markdown_split_table_cells($rows[$i]), $columnCount);
        $html .= '<tr>';
        foreach ($rowCells as $cell) {
            $html .= '<td>' . markdown_escape_html($cell) . '</td>';
        }
        $html .= '</tr>';
    }

    $html .= '</tbody></table>';
    return $html;
}

function markdown_extract_tables_to_placeholders($markdown, &$tableMap)
{
    $markdown = (string)($markdown ?? '');
    $tableMap = [];
    if ($markdown === '') {
        return '';
    }

    $lines = preg_split('/\r\n|\r|\n/', $markdown);
    if (!is_array($lines) || !$lines) {
        return $markdown;
    }

    $result = [];
    $lineCount = count($lines);
    $i = 0;
    $tableIndex = 0;
    $inFence = false;

    while ($i < $lineCount) {
        $line = $lines[$i];
        $trimmed = trim((string)$line);

        if (preg_match('/^(```|~~~)/', $trimmed)) {
            $inFence = !$inFence;
            $result[] = $line;
            $i++;
            continue;
        }

        if ($inFence) {
            $result[] = $line;
            $i++;
            continue;
        }

        $headerLine = $lines[$i];
        $separatorLine = ($i + 1 < $lineCount) ? $lines[$i + 1] : '';

        if (!markdown_is_table_row($headerLine) || !markdown_is_table_separator($separatorLine)) {
            $result[] = $line;
            $i++;
            continue;
        }

        $tableRows = [$headerLine, $separatorLine];
        $j = $i + 2;
        while ($j < $lineCount && markdown_is_table_row($lines[$j])) {
            $tableRows[] = $lines[$j];
            $j++;
        }

        $tableHtml = markdown_render_table_html($tableRows);
        if ($tableHtml === '') {
            $result[] = $line;
            $i++;
            continue;
        }

        $token = "@@MD_TABLE_{$tableIndex}@@";
        $tableMap[$token] = $tableHtml;
        $result[] = $token;
        $tableIndex++;
        $i = $j;
    }

    return implode("\n", $result);
}

function markdown_restore_table_placeholders_html($html, array $tableMap)
{
    $html = (string)($html ?? '');
    if ($html === '' || empty($tableMap)) {
        return $html;
    }

    foreach ($tableMap as $token => $tableHtml) {
        $html = str_replace('<p>' . $token . '</p>', $tableHtml, $html);
        $html = str_replace($token, $tableHtml, $html);
    }

    return $html;
}

function markdown_preserve_extra_blank_lines($markdown)
{
    $markdown = (string)($markdown ?? '');
    if ($markdown === '') {
        return '';
    }

    $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);

    return preg_replace_callback('/\n{3,}/', function ($matches) {
        $newlineCount = strlen($matches[0]);
        $extraBlankLines = $newlineCount - 2;

        $result = "\n\n";
        for ($i = 0; $i < $extraBlankLines; $i++) {
            $result .= "@@MD_EXTRA_BLANK_LINE@@\n";
        }

        return $result;
    }, $markdown);
}

function markdown_restore_extra_blank_lines_html($html)
{
    $html = (string)($html ?? '');
    if ($html === '') {
        return '';
    }

    $html = str_replace("\0A0", '', $html);
    $html = str_replace(['<p>A0</p>', '<p>\0A0</p>'], '', $html);

    $html = str_replace('<p>@@MD_EXTRA_BLANK_LINE@@</p>', '<p class="md-extra-blank-line" aria-hidden="true"><br></p>', $html);
    $html = str_replace('@@MD_EXTRA_BLANK_LINE@@<br />', '<br>', $html);
    $html = str_replace('@@MD_EXTRA_BLANK_LINE@@', '<br>', $html);

    return $html;
}

function markdown_get_link_meta_cache_paths($url)
{
    $cacheDir = BASE_PATH . UPLOAD_PATH . 'cache/';
    $cacheKey = md5('md-link-meta:' . $url);

    return [
        'dir' => $cacheDir,
        'meta' => $cacheDir . $cacheKey . '.link-meta',
        'ttl' => 7 * 24 * 3600,
    ];
}

function markdown_read_cached_link_meta($url)
{
    $paths = markdown_get_link_meta_cache_paths($url);
    if (!file_exists($paths['meta'])) {
        return null;
    }

    if ((time() - filemtime($paths['meta'])) >= $paths['ttl']) {
        return null;
    }

    $raw = @file_get_contents($paths['meta']);
    if ($raw === false || $raw === '') {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['title'])) {
        return null;
    }

    return [
        'title' => trim((string)($data['title'] ?? '')),
        'host' => trim((string)($data['host'] ?? '')),
    ];
}

function markdown_write_cached_link_meta($url, $title, $host)
{
    $paths = markdown_get_link_meta_cache_paths($url);
    if (!is_dir($paths['dir'])) {
        @mkdir($paths['dir'], 0755, true);
    }

    @file_put_contents($paths['meta'], json_encode([
        'title' => $title,
        'host' => $host,
        'checked_at' => time(),
    ], JSON_UNESCAPED_UNICODE));
}

function markdown_is_private_ipv4($ip)
{
    $longIp = ip2long($ip);
    if ($longIp === false) {
        return false;
    }

    $privateRanges = [
        ['127.0.0.0', '127.255.255.255'],
        ['10.0.0.0', '10.255.255.255'],
        ['172.16.0.0', '172.31.255.255'],
        ['192.168.0.0', '192.168.255.255'],
        ['169.254.0.0', '169.254.255.255'],
        ['0.0.0.0', '0.255.255.255'],
    ];

    foreach ($privateRanges as $range) {
        $start = ip2long($range[0]);
        $end = ip2long($range[1]);
        if ($longIp >= $start && $longIp <= $end) {
            return true;
        }
    }

    return false;
}

function markdown_is_safe_remote_url($url)
{
    if (!preg_match('/^https?:\/\//i', (string)$url)) {
        return false;
    }

    $parsed = parse_url($url);
    $host = $parsed['host'] ?? '';
    if (!$host) {
        return false;
    }

    $ips = @gethostbynamel($host);
    if (is_array($ips) && !empty($ips)) {
        foreach ($ips as $ip) {
            if (markdown_is_private_ipv4($ip)) {
                return false;
            }
        }
    }

    return true;
}

function markdown_extract_title_from_html($html)
{
    if (!is_string($html) || $html === '') {
        return '';
    }

    if (!preg_match('/<title\b[^>]*>(.*?)<\/title>/isu', $html, $matches)) {
        return '';
    }

    $title = html_entity_decode(strip_tags((string)$matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title = preg_replace('/\s+/u', ' ', trim($title));
    return (string)$title;
}

function markdown_detect_html_charset($html, $contentType)
{
    $contentType = (string)$contentType;
    if (preg_match('/charset\s*=\s*([a-zA-Z0-9_\-]+)/i', $contentType, $m)) {
        return strtoupper(trim($m[1]));
    }

    if (preg_match('/<meta[^>]+charset=["\']?\s*([a-zA-Z0-9_\-]+)/i', (string)$html, $m)) {
        return strtoupper(trim($m[1]));
    }

    if (preg_match('/<meta[^>]+content=["\'][^"\']*charset=([a-zA-Z0-9_\-]+)/i', (string)$html, $m)) {
        return strtoupper(trim($m[1]));
    }

    return 'UTF-8';
}

function markdown_convert_html_to_utf8($html, $contentType)
{
    $charset = markdown_detect_html_charset($html, $contentType);
    if ($charset && strtoupper($charset) !== 'UTF-8' && function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($html, 'UTF-8', $charset);
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }
    }

    return (string)$html;
}

function markdown_fetch_link_meta($url)
{
    $url = markdown_decode_url_if_needed($url);
    if ($url === '' || !markdown_is_safe_remote_url($url)) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'GalError-LinkMeta/1.0',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.2'],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if (!is_string($response) || $response === '' || $httpCode < 200 || $httpCode >= 400) {
        return null;
    }

    if ($contentType !== '' && stripos($contentType, 'text/html') === false) {
        return null;
    }

    $html = markdown_convert_html_to_utf8($response, $contentType);
    $title = markdown_extract_title_from_html($html);
    if ($title === '') {
        return null;
    }

    if (mb_strlen($title, 'UTF-8') > 120) {
        $title = mb_substr($title, 0, 120, 'UTF-8') . '…';
    }

    $host = parse_url($effectiveUrl ?: $url, PHP_URL_HOST);
    $host = trim((string)$host);

    return ['title' => $title, 'host' => $host];
}

function markdown_get_link_meta($url)
{
    $url = markdown_decode_url_if_needed($url);
    if ($url === '' || markdown_is_renderable_image_url($url)) {
        return null;
    }

    $cached = markdown_read_cached_link_meta($url);
    if ($cached !== null) {
        return $cached;
    }

    $meta = markdown_fetch_link_meta($url);
    if ($meta !== null && !empty($meta['title'])) {
        markdown_write_cached_link_meta($url, $meta['title'], $meta['host'] ?? '');
    }

    return $meta;
}

function markdown_should_replace_link_text($text, $href)
{
    $text = trim((string)$text);
    $href = trim((string)$href);
    if ($text === '' || $href === '') {
        return false;
    }

    $decodedHref = markdown_decode_url_if_needed($href);
    $decodedText = markdown_decode_url_if_needed($text);

    return $text === $href
        || $decodedText === $decodedHref
        || preg_match('/^https?:\/\//i', $text);
}

function markdown_format_link_display_text($title, $host)
{
    $title = trim((string)$title);
    $host = trim((string)$host);
    if ($title === '') {
        return '';
    }

    return $host !== '' ? ($title . ' · ' . $host) : $title;
}

function markdown_is_external_href($href)
{
    $href = trim((string)$href);
    if ($href === '') {
        return false;
    }

    if ($href[0] === '/' || $href[0] === '#' || $href[0] === '?') {
        return false;
    }

    if (!preg_match('#^(?:https?:)?//#i', $href)) {
        return false;
    }

    if (stripos($href, '//') === 0) {
        $href = 'https:' . $href;
    }

    $linkHost = strtolower((string)parse_url($href, PHP_URL_HOST));
    $siteHost = strtolower((string)parse_url(SITE_URL, PHP_URL_HOST));

    if ($linkHost === '' || $siteHost === '') {
        return true;
    }

    return $linkHost !== $siteHost;
}

class ParsedownToc extends Parsedown
{
    protected $headingCounter = 0;

    public function resetHeadingCounter()
    {
        $this->headingCounter = 0;
    }

    protected function blockHeader($Line)
    {
        $Block = parent::blockHeader($Line);
        if ($Block !== null) {
            $id = 'heading-' . $this->headingCounter++;
            $Block['element']['attributes'] = ['id' => $id];
        }
        return $Block;
    }

    protected function blockSetextHeader($Line, ?array $Block = null)
    {
        $Block = parent::blockSetextHeader($Line, $Block);
        if ($Block !== null) {
            $id = 'heading-' . $this->headingCounter++;
            $Block['element']['attributes'] = ['id' => $id];
        }
        return $Block;
    }

    protected function inlineLink($Excerpt)
    {
        $Inline = parent::inlineLink($Excerpt);
        if ($Inline !== null && isset($Inline['element']['attributes']['href'])) {
            list($href, $suffix) = markdown_split_url_suffix($Inline['element']['attributes']['href']);
            if ($href !== '') {
                $Inline['element']['attributes']['href'] = markdown_normalize_link_href($href);

                $rawText = isset($Inline['element']['text']) ? (string)$Inline['element']['text'] : '';
                $displayText = $rawText;

                if ($rawText !== ''
                    && markdown_should_replace_link_text($rawText, $href)
                    && !markdown_is_renderable_image_url($href)) {
                    $meta = markdown_get_link_meta($href);
                    if ($meta !== null && !empty($meta['title'])) {
                        $displayText = markdown_format_link_display_text($meta['title'], $meta['host'] ?? '');
                    }
                }

                if ($suffix !== '' && $displayText !== '') {
                    $displayText .= $suffix;
                }

                if ($displayText !== '') {
                    $Inline['element']['text'] = $displayText;
                }
            }
            if (markdown_is_external_href($Inline['element']['attributes']['href'])) {
                $Inline['element']['attributes']['target'] = '_blank';
                $Inline['element']['attributes']['rel'] = 'noopener noreferrer';
            } else {
                unset($Inline['element']['attributes']['target'], $Inline['element']['attributes']['rel']);
            }
        }
        return $Inline;
    }

    protected function inlineUrl($Excerpt)
    {
        $Inline = parent::inlineUrl($Excerpt);
        if ($Inline !== null && isset($Inline['element']['attributes']['href'])) {
            list($href, $suffix) = markdown_split_url_suffix($Inline['element']['attributes']['href']);
            if ($href !== '') {
                $Inline['element']['attributes']['href'] = markdown_normalize_link_href($href);

                $rawText = isset($Inline['element']['text']) ? (string)$Inline['element']['text'] : '';
                $displayText = $rawText;

                if ($rawText !== ''
                    && markdown_should_replace_link_text($rawText, $href)
                    && !markdown_is_renderable_image_url($href)) {
                    $meta = markdown_get_link_meta($href);
                    if ($meta !== null && !empty($meta['title'])) {
                        $displayText = markdown_format_link_display_text($meta['title'], $meta['host'] ?? '');
                    }
                }

                if ($suffix !== '' && $displayText !== '') {
                    $displayText .= $suffix;
                }

                if ($displayText !== '') {
                    $Inline['element']['text'] = $displayText;
                }
            }
            if (markdown_is_external_href($Inline['element']['attributes']['href'])) {
                $Inline['element']['attributes']['target'] = '_blank';
                $Inline['element']['attributes']['rel'] = 'noopener noreferrer';
            } else {
                unset($Inline['element']['attributes']['target'], $Inline['element']['attributes']['rel']);
            }
        }
        return $Inline;
    }

    protected function inlineUrlTag($Excerpt)
    {
        $Inline = parent::inlineUrlTag($Excerpt);
        if ($Inline !== null && isset($Inline['element']['attributes']['href'])) {
            list($href, $suffix) = markdown_split_url_suffix($Inline['element']['attributes']['href']);
            if ($href !== '') {
                $Inline['element']['attributes']['href'] = markdown_normalize_link_href($href);

                $rawText = isset($Inline['element']['text']) ? (string)$Inline['element']['text'] : '';
                $displayText = $rawText;

                if ($rawText !== ''
                    && markdown_should_replace_link_text($rawText, $href)
                    && !markdown_is_renderable_image_url($href)) {
                    $meta = markdown_get_link_meta($href);
                    if ($meta !== null && !empty($meta['title'])) {
                        $displayText = markdown_format_link_display_text($meta['title'], $meta['host'] ?? '');
                    }
                }

                if ($suffix !== '' && $displayText !== '') {
                    $displayText .= $suffix;
                }

                if ($displayText !== '') {
                    $Inline['element']['text'] = $displayText;
                }
            }
            if (markdown_is_external_href($Inline['element']['attributes']['href'])) {
                $Inline['element']['attributes']['target'] = '_blank';
                $Inline['element']['attributes']['rel'] = 'noopener noreferrer';
            } else {
                unset($Inline['element']['attributes']['target'], $Inline['element']['attributes']['rel']);
            }
        }
        return $Inline;
    }

    protected function inlineImage($Excerpt)
    {
        $Inline = parent::inlineImage($Excerpt);
        if ($Inline !== null && isset($Inline['element']['attributes']['src'])) {
            list($src) = markdown_split_url_suffix($Inline['element']['attributes']['src']);
            if ($src !== '') {
                $proxied = markdown_proxy_image_url($src);
                $Inline['element']['attributes']['src'] = $proxied;
                $Inline['element']['attributes']['data-viewer-src'] = $proxied;
                $Inline['element']['attributes']['class'] = trim(($Inline['element']['attributes']['class'] ?? '') . ' js-image-viewer-trigger');
                $Inline['element']['attributes']['data-viewer-alt'] = $Inline['element']['attributes']['alt'] ?? '图片预览';
            }
            $Inline['element']['attributes']['loading'] = 'lazy';
            $Inline['element']['attributes']['referrerpolicy'] = 'no-referrer';
        }
        return $Inline;
    }
}

function md_to_html($markdown) {
    static $parsedown = null;
    if ($parsedown === null) {
        $parsedown = new ParsedownToc();
        $parsedown->setSafeMode(true);
        $parsedown->setBreaksEnabled(true);
    }
    $parsedown->resetHeadingCounter();

    $normalizedMarkdown = markdown_preserve_extra_blank_lines($markdown);
    $normalizedMarkdown = markdown_normalize_tables(markdown_normalize_plain_image_urls(markdown_normalize_plain_links($normalizedMarkdown)));

    $tableMap = [];
    $normalizedMarkdown = markdown_extract_tables_to_placeholders($normalizedMarkdown, $tableMap);

    $html = $parsedown->text($normalizedMarkdown);
    $html = markdown_restore_table_placeholders_html($html, $tableMap);
    return markdown_restore_extra_blank_lines_html($html);
}

function extract_headings_from_markdown($markdown) {
    $headings = [];
    $index = 0;
    $lines = explode("\n", $markdown ?? '');
    $lineCount = count($lines);

    for ($i = 0; $i < $lineCount; $i++) {
        $line = $lines[$i];
        if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
            $headings[] = [
                'level' => strlen($m[1]),
                'text'  => trim($m[2], '# '),
                'id'    => 'heading-' . $index,
            ];
            $index++;
        } elseif ($i + 1 < $lineCount && trim($line) !== '') {
            $nextLine = $lines[$i + 1];
            if (preg_match('/^=+\s*$/', $nextLine)) {
                $headings[] = [
                    'level' => 1,
                    'text'  => trim($line),
                    'id'    => 'heading-' . $index,
                ];
                $index++;
                $i++;
            } elseif (preg_match('/^-+\s*$/', $nextLine) && strlen(trim($nextLine)) >= 2) {
                $headings[] = [
                    'level' => 2,
                    'text'  => trim($line),
                    'id'    => 'heading-' . $index,
                ];
                $index++;
                $i++;
            }
        }
    }

    return $headings;
}
