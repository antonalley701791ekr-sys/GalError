<?php
function readWhitelistDomains(string $file): array {
    if (!file_exists($file)) return [];
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return [];
    $domains = [];
    foreach ($lines as $line) {
        $domain = trim(mb_strtolower($line, 'UTF-8'));
        if ($domain !== '') $domains[] = $domain;
    }
    $domains = array_values(array_unique($domains));
    sort($domains, SORT_NATURAL | SORT_FLAG_CASE);
    return $domains;
}
function normalizeWhitelistInput(string $text): array {
    $text = str_replace(["\r\n", "\r", ',', ';', '，', '；'], "\n", $text);
    $lines = explode("\n", $text);
    $domains = [];
    foreach ($lines as $line) {
        $domain = trim(mb_strtolower($line, 'UTF-8'));
        if ($domain === '') continue;
        $domain = preg_replace('#^https?://#i', '', $domain);
        $domain = preg_replace('#/.*$#', '', $domain);
        $domain = trim($domain, ". \t\n\r\0\x0B");
        if ($domain === '') continue;
        if (!preg_match('/^[a-z0-9.-]+$/i', $domain)) continue;
        $domains[] = $domain;
    }
    $domains = array_values(array_unique($domains));
    sort($domains, SORT_NATURAL | SORT_FLAG_CASE);
    return $domains;
}
function saveWhitelistDomains(string $file, array $domains): bool {
    $content = $domains ? implode(PHP_EOL, $domains) . PHP_EOL : '';
    return @file_put_contents($file, $content, LOCK_EX) !== false;
}
