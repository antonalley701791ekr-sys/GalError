<?php
function loadSensitiveLogsQueryContext(array $filters, string $logFile): array {
    $keyword = trim($filters['keyword'] ?? '');
    $domainFilter = trim($filters['domain'] ?? '');
    $limit = max(20, min(200, intval($filters['limit'] ?? 100)));
    $records = [];
    $whitelistDomains = array_keys(loadSensitiveUrlWhitelist());
    $whitelistWords = array_keys(loadSensitiveWordWhitelist());

    if (file_exists($logFile)) {
        $rawLines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($rawLines !== false) {
            $rawLines = array_reverse($rawLines);
            foreach ($rawLines as $idx => $line) {
                $decoded = json_decode($line, true);
                if (!is_array($decoded)) {
                    continue;
                }
                $payload = $decoded['payload'] ?? [];
                $textPreview = (string)($payload['text_preview'] ?? '');
                $matchedWord = (string)($payload['matched_word'] ?? '');
                $domains = normalizeSensitiveWhitelistDomains($payload['domains'] ?? []);
                $nonWhitelistedDomains = normalizeSensitiveWhitelistDomains($payload['non_whitelisted_domains'] ?? []);
                if ($keyword !== '') {
                    $haystack = mb_strtolower($matchedWord . "\n" . $textPreview, 'UTF-8');
                    if (mb_stripos($haystack, mb_strtolower($keyword, 'UTF-8'), 0, 'UTF-8') === false) continue;
                }
                if ($domainFilter !== '') {
                    $matched = false;
                    foreach ((array)$domains as $domain) {
                        if (mb_stripos((string)$domain, $domainFilter, 0, 'UTF-8') !== false) { $matched = true; break; }
                    }
                    if (!$matched) continue;
                }
                $hitUsername = trim((string)($payload['hit_username'] ?? '')) ?: '游客';
                $scene = trim((string)($payload['scene'] ?? '')) ?: '未知场景';
                $page = trim((string)($payload['page'] ?? '')) ?: (string)($decoded['uri'] ?? '');
                $records[] = [
                    'id' => 'log_' . $idx,
                    'time' => $decoded['time'] ?? '',
                    'hit_username' => $hitUsername,
                    'scene' => $scene,
                    'page' => $page,
                    'ip' => $decoded['ip'] ?? '',
                    'uri' => $decoded['uri'] ?? '',
                    'method' => $decoded['method'] ?? '',
                    'matched_word' => $matchedWord,
                    'domains' => $domains,
                    'non_whitelisted_domains' => $nonWhitelistedDomains,
                    'text_preview' => $textPreview,
                ];
                if (count($records) >= $limit) break;
            }
        }
    }

    $filteredCandidateDomains = [];
    foreach ($records as $record) {
        $filteredCandidateDomains = array_merge($filteredCandidateDomains, $record['non_whitelisted_domains']);
    }

    return compact('records', 'whitelistDomains', 'whitelistWords', 'filteredCandidateDomains', 'keyword', 'domainFilter', 'limit');
}
