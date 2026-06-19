<?php
/**
 * 敏感词过滤引擎（DFA 算法）
 * 使用 Trie 前缀树进行高效敏感词匹配
 */

if (!defined('SENSITIVE_LEXICON_DIR')) {
    $lexiconDir = dirname(__DIR__) . '/data/lexicon/';
    if (!is_dir($lexiconDir)) {
        $lexiconDir = dirname(__DIR__, 2) . '/Sensitive-lexicon-1.2/Sensitive-lexicon-1.2/Vocabulary/';
    }
    define('SENSITIVE_LEXICON_DIR', $lexiconDir);
}

if (!defined('SENSITIVE_LEXICON_FILES')) {
    define('SENSITIVE_LEXICON_FILES', [
        SENSITIVE_LEXICON_DIR . '色情词库.txt',
        SENSITIVE_LEXICON_DIR . '色情类型.txt',
        SENSITIVE_LEXICON_DIR . '暴恐词库.txt',
        SENSITIVE_LEXICON_DIR . '广告类型.txt',
        SENSITIVE_LEXICON_DIR . '涉枪涉爆.txt',
        SENSITIVE_LEXICON_DIR . '补充词库.txt',
        SENSITIVE_LEXICON_DIR . '其他词库.txt',
    ]);
}

if (!defined('SENSITIVE_URL_WHITELIST_FILE')) {
    define('SENSITIVE_URL_WHITELIST_FILE', SENSITIVE_LEXICON_DIR . 'url_whitelist.txt');
}

if (!defined('SENSITIVE_HIT_LOG_FILE')) {
    define('SENSITIVE_HIT_LOG_FILE', dirname(__DIR__) . '/data/sensitive_hits.log');
}

if (!defined('SENSITIVE_WORD_WHITELIST_FILE')) {
    define('SENSITIVE_WORD_WHITELIST_FILE', SENSITIVE_LEXICON_DIR . 'word_whitelist.txt');
}

function shouldIgnoreSensitiveWord($word) {
    $word = trim(mb_strtolower((string) $word, 'UTF-8'));
    if ($word === '') {
        return true;
    }

    if (preg_match('/^\d+$/', $word) === 1) {
        return true;
    }

    static $noiseWords = [
        'www' => true,
        'http' => true,
        'https' => true,
        'html' => true,
        'htm' => true,
        'php' => true,
        'asp' => true,
        'aspx' => true,
        'jsp' => true,
        'com' => true,
        'cn' => true,
        'net' => true,
        'org' => true,
        '发票' => true,
        '无码' => true,
        '代理' => true,
    ];

    return isset($noiseWords[$word]);
}

function loadSensitiveWordList() {
    static $words = null;
    if ($words !== null) {
        return $words;
    }

    $words = [];
    foreach (SENSITIVE_LEXICON_FILES as $file) {
        if (!file_exists($file)) {
            continue;
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            continue;
        }
        $sample = implode('', array_slice($lines, 0, min(50, count($lines))));
        $encoding = mb_detect_encoding($sample, ['UTF-8', 'GBK', 'GB2312', 'BIG5'], true);
        foreach ($lines as $line) {
            if ($encoding && $encoding !== 'UTF-8') {
                $line = mb_convert_encoding($line, 'UTF-8', $encoding);
            }
            $line = trim($line);
            if ($line !== '') {
                $words[] = mb_strtolower($line, 'UTF-8');
            }
        }
    }

    $words = array_unique($words);
    $words = array_filter($words, function ($w) {
        return mb_strlen($w, 'UTF-8') >= 2 && !shouldIgnoreSensitiveWord($w);
    });

    return array_values($words);
}

function buildDFATrie(array $words) {
    $trie = [];
    foreach ($words as $word) {
        $node = &$trie;
        $len = mb_strlen($word, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($word, $i, 1, 'UTF-8');
            if (!isset($node[$char])) {
                $node[$char] = [];
            }
            $node = &$node[$char];
        }
        $node['__end__'] = true;
        unset($node);
    }
    return $trie;
}

function getSensitiveDFATrie() {
    static $trie = null;
    if ($trie !== null) {
        return $trie;
    }
    $trie = buildDFATrie(loadSensitiveWordList());
    return $trie;
}

function loadSensitiveUrlWhitelist() {
    static $domains = null;
    if ($domains !== null) {
        return $domains;
    }

    $domains = [];
    if (!file_exists(SENSITIVE_URL_WHITELIST_FILE)) {
        return $domains;
    }

    $lines = @file(SENSITIVE_URL_WHITELIST_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return $domains;
    }

    foreach ($lines as $line) {
        $domain = trim(mb_strtolower($line, 'UTF-8'));
        if ($domain !== '') {
            $domains[$domain] = true;
        }
    }

    return $domains;
}

function normalizeSensitiveWhitelistWords(array $items) {
    $words = [];
    foreach ($items as $item) {
        $word = trim(mb_strtolower((string)$item, 'UTF-8'));
        if ($word === '') {
            continue;
        }
        if (mb_strlen($word, 'UTF-8') < 2 || mb_strlen($word, 'UTF-8') > 64) {
            continue;
        }
        $words[$word] = true;
    }

    $words = array_keys($words);
    sort($words, SORT_NATURAL | SORT_FLAG_CASE);
    return $words;
}

function loadSensitiveWordWhitelist() {
    static $words = null;
    if ($words !== null) {
        return $words;
    }

    $words = [];
    if (!file_exists(SENSITIVE_WORD_WHITELIST_FILE)) {
        return $words;
    }

    $lines = @file(SENSITIVE_WORD_WHITELIST_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return $words;
    }

    foreach ($lines as $line) {
        $word = trim(mb_strtolower((string)$line, 'UTF-8'));
        if ($word !== '') {
            $words[$word] = true;
        }
    }

    return $words;
}

function saveSensitiveWordWhitelist(array $words) {
    $words = normalizeSensitiveWhitelistWords($words);
    $content = $words ? implode(PHP_EOL, $words) . PHP_EOL : '';
    return @file_put_contents(SENSITIVE_WORD_WHITELIST_FILE, $content, LOCK_EX) !== false;
}

function appendSensitiveWordWhitelist(array $words) {
    $current = array_keys(loadSensitiveWordWhitelist());
    $merged = normalizeSensitiveWhitelistWords(array_merge($current, $words));
    if (!saveSensitiveWordWhitelist($merged)) {
        return ['success' => false, 'words' => $current];
    }
    return ['success' => true, 'words' => $merged];
}

function resolveSensitiveHitSceneLabel($scene) {
    $scene = trim((string)$scene);
    if ($scene !== '') {
        return $scene;
    }

    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if (str_contains($uri, '/api/private_msg.php')) {
        return '私信聊天';
    }
    if (str_contains($uri, '/api/comment.php')) {
        return '评论回复';
    }
    if (str_contains($uri, '/submit_article.php')) {
        return '文章投稿';
    }
    if (str_contains($uri, '/submit_discussion.php')) {
        return '话题投稿';
    }
    if (str_contains($uri, '/submit_game.php')) {
        return '游戏投稿';
    }
    if (str_contains($uri, '/submit.php')) {
        return '报错投稿';
    }

    return '未知场景';
}

function getCurrentSensitiveHitUserInfo(array $context = []) {
    $username = trim((string)($context['hit_username'] ?? ''));
    $userId = (int)($context['hit_user_id'] ?? 0);

    if ($username !== '' || $userId > 0) {
        return ['hit_user_id' => $userId, 'hit_username' => $username];
    }

    if (function_exists('isUserLoggedIn') && isUserLoggedIn() && function_exists('getCurrentUserId')) {
        $uid = (int)getCurrentUserId();
        $uname = trim((string)($_SESSION['user_username'] ?? ''));
        return ['hit_user_id' => $uid, 'hit_username' => $uname];
    }

    if (!empty($_SESSION['admin_logged_in'])) {
        $uid = (int)($_SESSION['admin_id'] ?? 0);
        $uname = trim((string)($_SESSION['admin_username'] ?? ''));
        return ['hit_user_id' => $uid, 'hit_username' => $uname];
    }

    return ['hit_user_id' => 0, 'hit_username' => '游客'];
}

function normalizeSensitiveWhitelistDomains(array $items) {
    $domains = [];
    foreach ($items as $item) {
        $domain = trim(mb_strtolower((string) $item, 'UTF-8'));
        if ($domain === '') {
            continue;
        }
        $domain = preg_replace('#^https?://#i', '', $domain);
        $domain = preg_replace('#/.*$#', '', $domain);
        $domain = trim($domain, ". \t\n\r\0\x0B");
        if ($domain === '') {
            continue;
        }
        if (!preg_match('/^[a-z0-9.-]+$/i', $domain)) {
            continue;
        }
        $domains[$domain] = true;
    }

    $domains = array_keys($domains);
    sort($domains, SORT_NATURAL | SORT_FLAG_CASE);
    return $domains;
}

function saveSensitiveUrlWhitelist(array $domains) {
    $domains = normalizeSensitiveWhitelistDomains($domains);
    $content = $domains ? implode(PHP_EOL, $domains) . PHP_EOL : '';
    return @file_put_contents(SENSITIVE_URL_WHITELIST_FILE, $content, LOCK_EX) !== false;
}

function appendSensitiveUrlWhitelist(array $domains) {
    $current = array_keys(loadSensitiveUrlWhitelist());
    $merged = normalizeSensitiveWhitelistDomains(array_merge($current, $domains));
    if (!saveSensitiveUrlWhitelist($merged)) {
        return ['success' => false, 'domains' => $current];
    }
    return ['success' => true, 'domains' => $merged];
}

function extractSensitiveDomains($text) {
    $domains = [];
    if (!preg_match_all('/(?:https?:\/\/|www\.)[^\s<>"\']+/iu', $text, $matches)) {
        return $domains;
    }

    foreach ($matches[0] as $match) {
        $candidate = $match;
        if (stripos($candidate, 'http://') !== 0 && stripos($candidate, 'https://') !== 0) {
            $candidate = 'http://' . $candidate;
        }
        $host = parse_url($candidate, PHP_URL_HOST);
        if ($host) {
            $domains[] = mb_strtolower($host, 'UTF-8');
        }
    }

    return array_values(array_unique($domains));
}

function isSensitiveWhitelistedDomain($domain) {
    $domain = trim(mb_strtolower($domain, 'UTF-8'));
    if ($domain === '') {
        return false;
    }

    $whitelist = loadSensitiveUrlWhitelist();
    if (isset($whitelist[$domain])) {
        return true;
    }

    foreach ($whitelist as $allowed => $_) {
        if ($domain !== $allowed && substr($domain, -strlen('.' . $allowed)) === '.' . $allowed) {
            return true;
        }
    }

    return false;
}

function logSensitiveHit(array $payload) {
    $logDir = dirname(SENSITIVE_HIT_LOG_FILE);
    if (!is_dir($logDir)) {
        return;
    }

    $record = [
        'time' => date('c'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'payload' => $payload,
    ];

    @file_put_contents(
        SENSITIVE_HIT_LOG_FILE,
        json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function normalizeSensitiveText($text) {
    $text = (string) $text;
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    return mb_strtolower($text, 'UTF-8');
}

function maskBenignSensitiveSegments($text) {
    $patterns = [
        '/https?:\/\/[^\s<>"]+/iu',
        '/www\.[^\s<>"]+/iu',
        '/\b[\w.\-]+@[\w\-]+(?:\.[\w\-]+)+\b/u',
    ];

    foreach ($patterns as $pattern) {
        $text = preg_replace_callback($pattern, function ($matches) {
            $segment = $matches[0];
            if (preg_match('/^(?:https?:\/\/|www\.)/iu', $segment)) {
                $candidate = stripos($segment, 'http://') === 0 || stripos($segment, 'https://') === 0
                    ? $segment
                    : 'http://' . $segment;
                $host = parse_url($candidate, PHP_URL_HOST);
                if ($host && !isSensitiveWhitelistedDomain($host)) {
                    return $segment;
                }
            }

            return str_repeat(' ', mb_strlen($segment, 'UTF-8'));
        }, $text);
    }

    return $text;
}

function isAsciiWordChar($char) {
    return $char !== '' && preg_match('/[a-z0-9]/i', $char) === 1;
}

function shouldUseAsciiWordBoundary($word) {
    return preg_match('/[a-z]/i', $word) === 1 && preg_match('/^[a-z0-9._\-]+$/i', $word) === 1;
}

function isAsciiWordBoundaryMatch($text, $start, $length) {
    $prevChar = $start > 0 ? mb_substr($text, $start - 1, 1, 'UTF-8') : '';
    $nextIndex = $start + $length;
    $textLen = mb_strlen($text, 'UTF-8');
    $nextChar = $nextIndex < $textLen ? mb_substr($text, $nextIndex, 1, 'UTF-8') : '';

    return !isAsciiWordChar($prevChar) && !isAsciiWordChar($nextChar);
}

function containsSensitiveWord($text, array $context = []) {
    if ($text === null || $text === '') {
        return ['found' => false, 'word' => null];
    }

    $originalText = (string) $text;
    $domains = extractSensitiveDomains($originalText);
    $nonWhitelistedDomains = [];
    foreach ($domains as $domain) {
        if (!isSensitiveWhitelistedDomain($domain)) {
            $nonWhitelistedDomains[] = $domain;
        }
    }

    $trie = getSensitiveDFATrie();
    $wordWhitelist = loadSensitiveWordWhitelist();
    if (empty($trie)) {
        return ['found' => false, 'word' => null];
    }

    $text = normalizeSensitiveText($originalText);
    $text = maskBenignSensitiveSegments($text);
    $textLen = mb_strlen($text, 'UTF-8');

    for ($i = 0; $i < $textLen; $i++) {
        $node = $trie;
        $matchedWord = '';

        for ($j = $i; $j < $textLen; $j++) {
            $char = mb_substr($text, $j, 1, 'UTF-8');
            if (!isset($node[$char])) {
                break;
            }
            $node = $node[$char];
            $matchedWord .= $char;

            if (isset($node['__end__'])) {
                if (shouldIgnoreSensitiveWord($matchedWord)) {
                    continue;
                }

                if (shouldUseAsciiWordBoundary($matchedWord) && !isAsciiWordBoundaryMatch($text, $i, mb_strlen($matchedWord, 'UTF-8'))) {
                    continue;
                }

                if (isset($wordWhitelist[$matchedWord])) {
                    continue;
                }

                $userInfo = getCurrentSensitiveHitUserInfo($context);
                $scene = resolveSensitiveHitSceneLabel($context['scene'] ?? '');
                $page = trim((string)($context['page'] ?? ''));
                if ($page === '') {
                    $page = (string)($_SERVER['REQUEST_URI'] ?? '');
                }

                logSensitiveHit([
                    'matched_word' => $matchedWord,
                    'domains' => $domains,
                    'non_whitelisted_domains' => $nonWhitelistedDomains,
                    'text_preview' => mb_substr($originalText, 0, 200, 'UTF-8'),
                    'scene' => $scene,
                    'page' => $page,
                    'hit_user_id' => $userInfo['hit_user_id'],
                    'hit_username' => $userInfo['hit_username'],
                ]);

                return ['found' => true, 'word' => $matchedWord];
            }
        }
    }

    return ['found' => false, 'word' => null];
}
