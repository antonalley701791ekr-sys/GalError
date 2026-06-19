<?php
/**
 * 游戏标题敏感词脱敏工具
 * 将日语敏感词替换为等长度的 ○ 字符，用于前台展示
 */

require_once __DIR__ . '/config.php';

// 敏感词文件路径（使用相对路径，兼容不同部署环境）
if (!defined('SENSITIVE_WORDS_FILE')) {
    // 优先使用项目内置词库
    $jpWordsFile = dirname(__DIR__) . '/data/lexicon/日语敏感词.txt';
    if (!file_exists($jpWordsFile)) {
        // 回退到项目外部词库（开发环境）
        $jpWordsFile = dirname(__DIR__, 2) . '/日语敏感词.txt';
    }
    define('SENSITIVE_WORDS_FILE', $jpWordsFile);
}

/**
 * 加载并缓存日语敏感词列表
 * @return array 按 mb_strlen 降序排列的日语敏感词数组
 */
function loadSensitiveWords() {
    static $words = null;
    if ($words !== null) {
        return $words;
    }

    $words = [];

    $content = @file_get_contents(SENSITIVE_WORDS_FILE);
    if ($content === false) {
        return $words;
    }

    // 提取所有单引号包裹的词条
    if (!preg_match_all("/'([^']+)'/u", $content, $matches)) {
        return $words;
    }

    $candidates = array_unique($matches[1]);

    // Galgame 标题常见词白名单（这些词在游戏标题中属于正常用语，不应脱敏）
    $whitelist = [
        // 家庭称谓（游戏标题常见）
        '姉妹', '兄弟', '息子', '母娘', '義母', '義父', '実母', '実父',
        // 幻想/奇幻类（Galgame 常见题材）
        '魔女', '女王', '悪魔', 'サキュバス', 'サキュ', '夢魔', '魅了',
        // 常见游戏类型/标签
        'ハーレム', 'コスプレ', 'ペット',
        // 有非色情含义的常见词
        '変態', '異常', 'メス', '近親', '禁断', '背徳', '不倫',
    ];
    $whitelistMap = array_flip($whitelist);

    // 仅保留含日语字符的词（平假名、片假名、CJK汉字、片假名扩展、半角片假名）
    $jpPattern = '/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}\x{4E00}-\x{9FFF}\x{31F0}-\x{31FF}\x{FF65}-\x{FF9F}]/u';
    foreach ($candidates as $word) {
        $word = trim($word);
        if ($word === '' || !preg_match($jpPattern, $word)) {
            continue;
        }
        // 跳过单字（误伤率极高：娘、口、乳、耳、肉 等）
        if (mb_strlen($word, 'UTF-8') < 2) {
            continue;
        }
        // 跳过白名单词条
        if (isset($whitelistMap[$word])) {
            continue;
        }
        $words[] = $word;
    }

    // 按字符长度降序排序（长词优先匹配）
    usort($words, function($a, $b) {
        return mb_strlen($b, 'UTF-8') - mb_strlen($a, 'UTF-8');
    });

    return $words;
}

/**
 * 将标题中的敏感词替换为等长度的 ○
 * @param string $str 原始标题
 * @return string 脱敏后的标题
 */
function sanitizeTitle($str) {
    if ($str === null || $str === '' || !is_string($str)) {
        return '';
    }

    $words = loadSensitiveWords();
    foreach ($words as $word) {
        $replacement = str_repeat('○', mb_strlen($word, 'UTF-8'));
        $str = str_replace($word, $replacement, $str);
    }

    return $str;
}

/**
 * 安全输出脱敏后的标题（先脱敏再 HTML 转义）
 * @param string $str 原始标题
 * @return string HTML 安全的脱敏标题
 */
function hs($str) {
    return h(sanitizeTitle($str));
}
