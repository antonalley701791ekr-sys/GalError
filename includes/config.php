<?php
/**
 * 数据库配置文件
 */

// 时区设置（从阿里云迁移到海外服务器后，必须显式设定以保证 PHP date() 与 MySQL NOW() 一致）
date_default_timezone_set('Asia/Shanghai');

// ── 私有凭据加载（不纳入版本库、禁止 web 访问）──
// 优先级：环境变量 > includes/config.secret.php > 占位默认值
$__secret = is_file(__DIR__ . '/config.secret.php') ? (require __DIR__ . '/config.secret.php') : [];
if (!is_array($__secret)) { $__secret = []; }
if (!function_exists('galSecret')) {
    function galSecret($key, array $secret, $default = '') {
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        return $secret[$key] ?? $default;
    }
}

// 数据库连接配置
define('DB_HOST', galSecret('DB_HOST', $__secret, 'localhost'));
define('DB_NAME', galSecret('DB_NAME', $__secret, ''));
define('DB_USER', galSecret('DB_USER', $__secret, ''));
define('DB_PASS', galSecret('DB_PASS', $__secret, ''));
define('DB_CHARSET', 'utf8mb4');

// 网站基础配置
define('SITE_URL', 'https://galerror.top');
define('SITE_NAME', 'Galgame 报错解决百科');
define('BASE_PATH', dirname(__DIR__) . '/');
define('UPLOAD_PATH', 'data/');
define('UPLOAD_URL', '/data/');  // 前端 URL 用绝对路径（避免在 /game/123 等美化URL下相对路径出错）
define('MAX_FILE_SIZE', 4 * 1024 * 1024); // 4MB
define('ONLINE_TIMEOUT_MINUTES', 10); // 在线状态超时（分钟）
define('ASSETS_VER', '20260614-comment-unify-1'); // 静态资源版本号，修改 CSS/JS 后递增以破缓存
// 数据库结构版本号。新增/修改迁移后递增本值，下次加载会自动执行一遍迁移（见 includes/migrations/runner.php）
define('SCHEMA_VERSION', '2026-06-13');

// API 安全策略
// Basic Auth 模式：off（默认关闭）| all（全部 API）| selected（仅指定接口）
define('API_BASIC_AUTH_MODE', 'off');
// 当 MODE=selected 时生效：逗号分隔 bucket 名称，如 "comment,private_msg"
define('API_BASIC_AUTH_ENDPOINTS', '');
define('API_BASIC_AUTH_USER', '');
define('API_BASIC_AUTH_PASS', '');
// 全局 API 限频默认值（每窗口秒内最大请求数）
define('API_RATE_LIMIT_ENABLED', true);
define('API_RATE_LIMIT_MAX', 120);
define('API_RATE_LIMIT_WINDOW', 60);

// VNDB API 配置
define('VNDB_API_URL', 'https://api.vndb.org/kana');

// 邮件配置
define('MAIL_FROM', 'noreply@galerror.top');
define('MAIL_FROM_NAME', SITE_NAME);

// Resend API 配置（推荐，通过 HTTPS 发送，不受 SMTP 端口封锁影响）
define('RESEND_API_KEY', galSecret('RESEND_API_KEY', $__secret, ''));

// SMTP 配置（备用，PHPMailer）
define('SMTP_HOST', galSecret('SMTP_HOST', $__secret, 'smtp.example.com'));
define('SMTP_PORT', (int) galSecret('SMTP_PORT', $__secret, 465));
define('SMTP_USER', galSecret('SMTP_USER', $__secret, ''));
define('SMTP_PASS', galSecret('SMTP_PASS', $__secret, ''));
define('SMTP_SECURE', galSecret('SMTP_SECURE', $__secret, 'ssl'));

// 统一日志工具（galLog / galLogError）—— 全局可用，外部依赖失败可留痕
require_once __DIR__ . '/logger.php';

// 创建数据库连接
function getDB() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // 确保 MySQL 会话时区与 PHP 一致
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (PDOException $e) {
            die('数据库连接失败: ' . $e->getMessage());
        }
    }
    
    return $pdo;
}

/**
 * 获取单个内容的浏览量
 */
function getViewCount($contentType, $contentId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT user_views, guest_views FROM view_counts WHERE content_type = ? AND content_id = ?");
    $stmt->execute([$contentType, $contentId]);
    $row = $stmt->fetch();
    if ($row) {
        return [
            'user_views' => (int)$row['user_views'],
            'guest_views' => (int)$row['guest_views'],
            'total_views' => (int)$row['user_views'] + (int)$row['guest_views']
        ];
    }
    return ['user_views' => 0, 'guest_views' => 0, 'total_views' => 0];
}

/**
 * 批量获取浏览量（用于列表页）
 */
function getViewCountsBatch($contentType, $contentIds) {
    if (empty($contentIds)) return [];
    $pdo = getDB();
    $placeholders = implode(',', array_fill(0, count($contentIds), '?'));
    $params = array_merge([$contentType], $contentIds);
    $stmt = $pdo->prepare("SELECT content_id, user_views, guest_views FROM view_counts WHERE content_type = ? AND content_id IN ($placeholders)");
    $stmt->execute($params);
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[$row['content_id']] = (int)$row['user_views'] + (int)$row['guest_views'];
    }
    return $result;
}

// 文章图片上传处理
function handleArticleImageUpload($file) {
    $relDir = UPLOAD_PATH . 'articles/';
    $absDir = BASE_PATH . $relDir;
    if (!is_dir($absDir)) {
        mkdir($absDir, 0755, true);
    }
    $check = validateUploadedImageFile($file, ['jpg', 'jpeg', 'png', 'gif', 'webp'], 4 * 1024 * 1024);
    if (!$check['ok']) {
        return ['success' => false, 'message' => $check['message']];
    }
    $ext = $check['ext'];
    $filename = date('YmdHis') . '_' . uniqid() . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $absDir . $filename)) {
        return ['success' => true, 'path' => $relDir . $filename, 'url' => '/' . $relDir . $filename];
    }
    return ['success' => false, 'message' => '文件保存失败'];
}

// URL 辅助函数
require_once __DIR__ . '/url_helpers.php';

// 安全的HTML输出函数
function h($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

// 邮箱脱敏函数
function maskEmail($email) {
    if (empty($email)) return '未设置';
    $parts = explode('@', $email);
    if (count($parts) !== 2) return '***';
    $local = $parts[0];
    $domain = $parts[1];
    $len = mb_strlen($local);
    if ($len <= 1) {
        $masked = $local . '**';
    } else {
        $masked = mb_substr($local, 0, 1) . '**' . mb_substr($local, -1);
    }
    return $masked . '@' . $domain;
}

// 获取客户端IP
function getClientIP() {
    $candidates = [];

    // Cloudflare 场景：优先使用真实来源 IP
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $candidates[] = trim((string)$_SERVER['HTTP_CF_CONNECTING_IP']);
    }

    // 常见反向代理头：取首个合法 IP（XFF 可能是逗号分隔链路）
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwardedIps = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach ($forwardedIps as $forwardedIp) {
            $forwardedIp = trim($forwardedIp);
            if ($forwardedIp !== '') {
                $candidates[] = $forwardedIp;
            }
        }
    }

    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $candidates[] = trim((string)$_SERVER['HTTP_X_REAL_IP']);
    }

    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $candidates[] = trim((string)$_SERVER['HTTP_CLIENT_IP']);
    }

    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $candidates[] = trim((string)$_SERVER['REMOTE_ADDR']);
    }

    foreach ($candidates as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return '';
}

/**
 * 统一的上传图片安全校验（多部分 $_FILES）
 *
 * 在“扩展名白名单”之外，额外做三层防护：
 *  1) 硬黑名单：可执行 / 可内嵌脚本的格式一律拒绝（即使白名单误配也挡得住）；
 *  2) 真实内容校验：必须是 getimagesize 能识别的栅格图片；
 *  3) 扩展名与真实图片类型一致（防止把脚本改成 .jpg 后缀绕过）。
 *
 * @return array ['ok'=>bool, 'message'=>string, 'ext'=>string]  ext 为校验通过的扩展名
 */
function validateUploadedImageFile($file, array $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'], $maxSize = null) {
    $maxSize = $maxSize ?? (defined('MAX_FILE_SIZE') ? MAX_FILE_SIZE : 4 * 1024 * 1024);

    if (!isset($file) || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => '文件上传失败', 'ext' => ''];
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > $maxSize) {
        $mb = round($maxSize / 1024 / 1024, 1);
        return ['ok' => false, 'message' => "图片大小需在 0 ~ {$mb}MB 之间", 'ext' => ''];
    }
    // 必须是经由 HTTP POST 上传的临时文件，杜绝伪造 tmp_name 进行路径读取
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'message' => '非法的上传来源', 'ext' => ''];
    }

    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));

    // 硬黑名单：无论白名单如何配置，这些格式永不允许
    static $denyExt = ['php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'pht', 'phps',
                       'svg', 'svgz', 'htm', 'html', 'xhtml', 'shtml', 'js', 'mjs', 'xml', 'swf'];
    if (in_array($ext, $denyExt, true)) {
        return ['ok' => false, 'message' => '出于安全考虑，该格式不允许上传', 'ext' => ''];
    }

    if (!in_array($ext, $allowedExt, true)) {
        return ['ok' => false, 'message' => '不支持的文件类型', 'ext' => ''];
    }

    // 真实内容校验：必须是可识别的栅格图片
    $info = @getimagesize($file['tmp_name']);
    if ($info === false || empty($info[2])) {
        return ['ok' => false, 'message' => '图片内容验证失败（文件不是有效图片）', 'ext' => ''];
    }

    // 扩展名必须与真实图片类型一致
    $typeToExt = [
        IMAGETYPE_JPEG => ['jpg', 'jpeg'],
        IMAGETYPE_PNG  => ['png'],
        IMAGETYPE_GIF  => ['gif'],
        IMAGETYPE_WEBP => ['webp'],
        IMAGETYPE_BMP  => ['bmp'],
    ];
    $realExts = $typeToExt[$info[2]] ?? [];
    if (!$realExts || !in_array($ext, $realExts, true)) {
        return ['ok' => false, 'message' => '文件真实类型与扩展名不符', 'ext' => ''];
    }

    return ['ok' => true, 'message' => '', 'ext' => $ext];
}

/**
 * 校验 ICO 图标文件的真实头部（站点 favicon 用）。
 */
function isValidIcoFile($path) {
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return false;
    }
    $head = fread($fh, 4);
    fclose($fh);
    // ICO 头: 00 00 01 00 ；CUR 头: 00 00 02 00
    return $head === "\x00\x00\x01\x00" || $head === "\x00\x00\x02\x00";
}

/**
 * 校验 SVG 是否安全（仅站点 Logo 等管理员上传场景）。
 * 拒绝可在浏览器中触发脚本执行 / 外部加载的内容，避免存储型 XSS。
 */
function isSafeSvgFile($path) {
    $content = @file_get_contents($path, false, null, 0, 512 * 1024);
    if ($content === false || $content === '') {
        return false;
    }
    $lower = strtolower($content);
    if (strpos($lower, '<svg') === false) {
        return false; // 不像 SVG
    }
    // 危险特征：脚本 / 伪协议 / 外链对象 / 实体
    $danger = ['<script', 'javascript:', '<foreignobject', '<iframe', '<embed',
               '<object', 'data:text/html', '<!entity', '<!doctype'];
    foreach ($danger as $needle) {
        if (strpos($lower, $needle) !== false) {
            return false;
        }
    }
    // 任意 on*= 事件处理器（onload / onclick / onmouseover ...）
    if (preg_match('/\son[a-z]+\s*=/i', $content)) {
        return false;
    }
    return true;
}

// 文件上传处理
function handleFileUpload($file, $allowedTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp']) {
    $check = validateUploadedImageFile($file, $allowedTypes, MAX_FILE_SIZE);
    if (!$check['ok']) {
        return ['success' => false, 'message' => $check['message']];
    }
    $extension = $check['ext'];

    // 生成唯一文件名
    $filename = date('YmdHis') . '_' . uniqid() . '.' . $extension;
    $absDir = BASE_PATH . UPLOAD_PATH;

    // 确保上传目录存在
    if (!is_dir($absDir)) {
        mkdir($absDir, 0755, true);
    }

    // 移动文件
    if (move_uploaded_file($file['tmp_name'], $absDir . $filename)) {
        return ['success' => true, 'filename' => $filename];
    } else {
        return ['success' => false, 'message' => '文件保存失败'];
    }
}

// VNDB API 调用（优化通道 + 兼容回退）
function fetchVNDBInfo($vndbId) {
    if (!preg_match('/^v\d+$/', $vndbId)) {
        return ['success' => false, 'message' => 'VNDB ID 格式错误'];
    }

    // 这里的“优化”可以理解为：先走更快的尝试路径，失败再回到原来的稳定路径。
    // 相关开关可在后台“站点外观”里配置。
    $opts = vndbGetRuntimeOptions();

    $cacheKey = 'vndb_' . strtolower($vndbId);
    $cached = vndbGetCache($cacheKey);
    if ($cached !== null) {
        return $cached;
    }

    $requestData = [
        'filters' => ['id', '=', $vndbId],
        'fields' => 'title,alttitle,aliases,titles.lang,titles.title,titles.latin,developers.name,released,platforms,image.url,image.sexual'
    ];

    $optimizedResult = null;
    if ($opts['enabled'] && !vndbCircuitIsOpen()) {
        $optimizedResult = vndbRequestOptimized($requestData);
        if (vndbIsResultUsable($optimizedResult)) {
            vndbCircuitMarkSuccess();
            vndbSetCache($cacheKey, $optimizedResult, $opts['cache_ttl']);
            return $optimizedResult;
        }
        vndbCircuitMarkFailure('优化通道返回不可用结果');
    }

    // 兜底：无论优化是否开启，都保留原来的稳定请求方式。
    $legacyResult = vndbRequestLegacy($requestData);
    if (vndbIsResultUsable($legacyResult)) {
        vndbSetCache($cacheKey, $legacyResult, $opts['cache_ttl']);
        return $legacyResult;
    }

    // 优化通道与稳定兜底均失败：记为外部依赖错误（VNDB 不可用），便于排查
    if (function_exists('galLog')) {
        galLogError('vndb', 'VNDB 请求失败：优化通道与稳定兜底均不可用', [
            'vndb_id' => $vndbId,
            'dep'     => 'external',
        ]);
    }

    if (is_array($optimizedResult) && isset($optimizedResult['message'])) {
        return $optimizedResult;
    }
    return $legacyResult;
}

function vndbSetting($key, $default = '') {
    static $settings = null;
    if ($settings === null) {
        $settings = [];
        try {
            $pdo = getDB();
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'vndb_%'");
            foreach ($stmt->fetchAll() as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Exception $e) {
            $settings = [];
        }
    }
    return isset($settings[$key]) && $settings[$key] !== '' ? $settings[$key] : $default;
}

function vndbGetRuntimeOptions() {
    // 给普通人的说明：
    // enabled：是否开启“先快后稳”的模式
    // failure_threshold：优化通道连续失败多少次后，临时停用优化（避免每次都超时）
    // open_seconds：临时停用优化的时长（秒）
    // cache_ttl：缓存保留多久（秒），减少重复请求
    $enabled = vndbSetting('vndb_opt_enabled', '1') === '1';
    $failureThreshold = max(1, intval(vndbSetting('vndb_opt_fail_threshold', '5')));
    $openSeconds = max(30, intval(vndbSetting('vndb_opt_open_seconds', '600')));
    $cacheTtl = max(60, intval(vndbSetting('vndb_cache_ttl', (string)(6 * 3600))));

    return [
        'enabled' => $enabled,
        'failure_threshold' => $failureThreshold,
        'open_seconds' => $openSeconds,
        'cache_ttl' => $cacheTtl,
    ];
}

function vndbRequestOptimized($requestData) {
    $opts = [
        CURLOPT_TIMEOUT => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
    ];

    if (defined('CURLOPT_TCP_KEEPALIVE')) {
        $opts[CURLOPT_TCP_KEEPALIVE] = 1;
    }
    if (defined('CURLOPT_TCP_KEEPIDLE')) {
        $opts[CURLOPT_TCP_KEEPIDLE] = 30;
    }
    if (defined('CURLOPT_TCP_KEEPINTVL')) {
        $opts[CURLOPT_TCP_KEEPINTVL] = 15;
    }

    return vndbRequestBase($requestData, $opts);
}

function vndbRequestLegacy($requestData) {
    return vndbRequestBase($requestData, [
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);
}

function vndbRequestBase($requestData, $extraOptions = []) {
    $ch = curl_init(VNDB_API_URL . '/vn');
    $baseOptions = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($requestData),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'GalError-VNDB/1.0',
    ];

    foreach ($extraOptions as $k => $v) {
        $baseOptions[$k] = $v;
    }

    curl_setopt_array($ch, $baseOptions);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['success' => false, 'message' => '无法连接到 VNDB API: ' . $curlError];
    }

    if ($httpCode !== 200) {
        return ['success' => false, 'message' => 'VNDB API 返回错误 (HTTP ' . $httpCode . ')'];
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['results']) || empty($data['results'])) {
        return ['success' => false, 'message' => '未找到游戏信息'];
    }

    return vndbTransformResult($data['results'][0]);
}

function vndbTransformResult($game) {
    $developer = '';
    if (isset($game['developers']) && !empty($game['developers'])) {
        $developer = $game['developers'][0]['name'] ?? '';
    }

    $romaji = '';
    if (isset($game['titles']) && is_array($game['titles'])) {
        foreach ($game['titles'] as $titleEntry) {
            if (($titleEntry['lang'] ?? '') === 'ja' && !empty($titleEntry['latin'])) {
                $romaji = $titleEntry['latin'];
                break;
            }
        }
    }
    if (empty($romaji)) {
        $romaji = $game['title'] ?? '';
    }

    $titleZh = '';
    if (isset($game['titles']) && is_array($game['titles'])) {
        foreach ($game['titles'] as $titleEntry) {
            if (($titleEntry['lang'] ?? '') === 'zh-Hans' && !empty($titleEntry['title'])) {
                $titleZh = $titleEntry['title'];
                break;
            }
        }
        if (empty($titleZh)) {
            foreach ($game['titles'] as $titleEntry) {
                if (($titleEntry['lang'] ?? '') === 'zh-Hant' && !empty($titleEntry['title'])) {
                    $titleZh = $titleEntry['title'];
                    break;
                }
            }
        }
    }

    $aliases = '';
    if (isset($game['aliases']) && is_array($game['aliases'])) {
        $aliases = implode(', ', $game['aliases']);
    }

    $platforms = '';
    if (isset($game['platforms']) && is_array($game['platforms'])) {
        $platforms = implode(', ', $game['platforms']);
    }

    $coverUrl = '';
    $coverSexual = 0;
    if (isset($game['image']) && !empty($game['image']['url'])) {
        $coverUrl = $game['image']['url'];
        $coverSexual = $game['image']['sexual'] ?? 0;
    }

    return [
        'success' => true,
        'data' => [
            'title' => $game['title'] ?? '',
            'title_jp' => $game['alttitle'] ?? '',
            'title_zh' => $titleZh,
            'romaji' => $romaji,
            'aliases' => $aliases,
            'developer' => $developer,
            'release_date' => $game['released'] ?? null,
            'platforms' => $platforms,
            'cover_url' => $coverUrl,
            'cover_sexual' => $coverSexual
        ]
    ];
}

function vndbIsResultUsable($result) {
    return is_array($result)
        && !empty($result['success'])
        && !empty($result['data'])
        && !empty($result['data']['title']);
}

function vndbCircuitFile() {
    $dir = BASE_PATH . UPLOAD_PATH . 'cache/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir . 'vndb_circuit.json';
}

function vndbCircuitState() {
    static $state = null;
    if ($state !== null) {
        return $state;
    }

    $file = vndbCircuitFile();
    if (!file_exists($file)) {
        $state = ['failures' => 0, 'open_until' => 0];
        return $state;
    }

    $json = @file_get_contents($file);
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        $state = ['failures' => 0, 'open_until' => 0];
        return $state;
    }

    $state = [
        'failures' => intval($decoded['failures'] ?? 0),
        'open_until' => intval($decoded['open_until'] ?? 0),
    ];
    return $state;
}

function vndbCircuitSave($state) {
    @file_put_contents(vndbCircuitFile(), json_encode($state, JSON_UNESCAPED_UNICODE));
}

function vndbCircuitIsOpen() {
    $state = vndbCircuitState();
    return intval($state['open_until'] ?? 0) > time();
}

function vndbCircuitMarkFailure($reason = '') {
    $state = vndbCircuitState();
    $opts = vndbGetRuntimeOptions();

    // 连续失败计数：优化通道失败一次就 +1
    $state['failures'] = intval($state['failures'] ?? 0) + 1;

    // 外部依赖失败留痕：便于区分“本地错误”与“VNDB 不可用”
    if (function_exists('galLog')) {
        galLog('vndb', 'warning', 'VNDB 优化通道失败', [
            'failures' => $state['failures'],
            'reason'   => $reason,
            'dep'      => 'external',
        ]);
    }

    // 达到阈值后：短时间“跳过优化通道”，直接使用稳定兜底通道
    if ($state['failures'] >= $opts['failure_threshold']) {
        $state['open_until'] = time() + $opts['open_seconds'];
        $state['failures'] = 0;
    }

    $state['updated_at'] = time();
    vndbCircuitSave($state);
}

function vndbCircuitMarkSuccess() {
    $state = ['failures' => 0, 'open_until' => 0, 'updated_at' => time()];
    vndbCircuitSave($state);
}

function vndbCacheDir() {
    $dir = BASE_PATH . UPLOAD_PATH . 'cache/vndb/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function vndbGetCache($key) {
    $file = vndbCacheDir() . preg_replace('/[^a-z0-9_\-]/i', '_', $key) . '.json';
    if (!file_exists($file)) {
        return null;
    }

    $json = @file_get_contents($file);
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        return null;
    }

    $expiresAt = intval($payload['expires_at'] ?? 0);
    if ($expiresAt <= time()) {
        @unlink($file);
        return null;
    }

    return $payload['value'] ?? null;
}

function vndbSetCache($key, $value, $ttlSeconds) {
    $file = vndbCacheDir() . preg_replace('/[^a-z0-9_\-]/i', '_', $key) . '.json';
    $payload = [
        'expires_at' => time() + max(60, intval($ttlSeconds)),
        'value' => $value,
    ];
    @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE));
}

function vndbCircuitPublicStatus() {
    $opts = vndbGetRuntimeOptions();
    $state = vndbCircuitState();
    $openUntil = intval($state['open_until'] ?? 0);
    $updatedAt = intval($state['updated_at'] ?? 0);

    return [
        'enabled' => $opts['enabled'],
        'is_open' => $openUntil > time(),
        'open_until' => $openUntil,
        'failures' => intval($state['failures'] ?? 0),
        'updated_at' => $updatedAt,
        'failure_threshold' => $opts['failure_threshold'],
        'open_seconds' => $opts['open_seconds'],
        'cache_ttl' => $opts['cache_ttl'],
    ];
}

function vndbCacheStats() {
    $dir = vndbCacheDir();
    $files = @glob($dir . '*.json') ?: [];
    return [
        'dir' => $dir,
        'count' => count($files),
    ];
}

// 封面图上传处理
function handleCoverUpload($file) {
    $relDir = UPLOAD_PATH . 'covers/';
    $absDir = BASE_PATH . $relDir;
    if (!is_dir($absDir)) {
        mkdir($absDir, 0755, true);
    }
    
    $check = validateUploadedImageFile($file, ['jpg', 'jpeg', 'png'], 4 * 1024 * 1024);
    if (!$check['ok']) {
        return ['success' => false, 'message' => $check['message']];
    }
    $extension = $check['ext'];
    $filename = date('YmdHis') . '_' . uniqid() . '.' . $extension;

    if (move_uploaded_file($file['tmp_name'], $absDir . $filename)) {
        return ['success' => true, 'path' => $relDir . $filename];
    } else {
        return ['success' => false, 'message' => '文件保存失败'];
    }
}

// 分页函数
function paginate($total, $page = 1, $perPage = 20) {
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;
    
    return [
        'page' => $page,
        'perPage' => $perPage,
        'total' => $total,
        'totalPages' => $totalPages,
        'offset' => $offset,
        'hasPrev' => $page > 1,
        'hasNext' => $page < $totalPages
    ];
}

function getCommentPerPage() {
    return 20;
}

function findCommentPage($contentType, $contentId, $commentId, $perPage = null) {
    $contentType = trim((string)$contentType);
    $contentId = (int)$contentId;
    $commentId = (int)$commentId;
    $perPage = $perPage ? (int)$perPage : getCommentPerPage();

    if ($contentType === '' || $contentId <= 0 || $commentId <= 0 || $perPage <= 0) {
        return 1;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT created_at, id FROM comments WHERE id = ? AND content_type = ? AND content_id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$commentId, $contentType, $contentId]);
    $target = $stmt->fetch();
    if (!$target) {
        return 1;
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE content_type = ? AND content_id = ? AND status = 'active' AND (created_at < ? OR (created_at = ? AND id <= ?))");
    $countStmt->execute([$contentType, $contentId, $target['created_at'], $target['created_at'], $commentId]);
    $position = (int)$countStmt->fetchColumn();
    if ($position <= 0) {
        return 1;
    }

    return max(1, (int)ceil($position / $perPage));
}

function buildCommentTargetUrl($contentType, $contentId, $commentId, $perPage = null) {
    $contentType = trim((string)$contentType);
    $contentId = (int)$contentId;
    $commentId = (int)$commentId;
    $commentHash = '#comment-' . $commentId;
    $commentPage = findCommentPage($contentType, $contentId, $commentId, $perPage);
    $params = ['id' => $contentId];
    if ($commentPage > 1) {
        $params['comment_page'] = $commentPage;
    }

    if ($contentType === 'discussion') {
        return '/discussion.php?' . http_build_query($params) . $commentHash;
    }
    if ($contentType === 'error') {
        return '/error_detail.php?' . http_build_query($params) . $commentHash;
    }
    if ($contentType === 'article') {
        return '/article.php?' . http_build_query($params) . $commentHash;
    }
    return null;
}

// 头像上传处理
function handleAvatarUpload($file) {
    $relDir = UPLOAD_PATH . 'avatars/';
    $absDir = BASE_PATH . $relDir;
    if (!is_dir($absDir)) {
        mkdir($absDir, 0755, true);
    }
    $check = validateUploadedImageFile($file, ['jpg', 'jpeg', 'png'], 4 * 1024 * 1024);
    if (!$check['ok']) {
        return ['success' => false, 'message' => $check['message']];
    }
    $ext = $check['ext'];
    $filename = date('YmdHis') . '_' . uniqid() . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $absDir . $filename)) {
        return ['success' => true, 'path' => $relDir . $filename];
    }
    return ['success' => false, 'message' => '文件保存失败'];
}

// 站点图片上传处理
function handleSiteImageUpload($file) {
    $relDir = UPLOAD_PATH . 'site/';
    $absDir = BASE_PATH . $relDir;
    if (!is_dir($absDir)) {
        mkdir($absDir, 0755, true);
    }
    if (!isset($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => '文件上传失败'];
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > 4 * 1024 * 1024) {
        return ['success' => false, 'message' => '图片大小不能超过 4MB'];
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'message' => '非法的上传来源'];
    }
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));

    // 栅格图走统一校验；favicon(ico) 与 logo(svg) 单独做安全校验
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
        $check = validateUploadedImageFile($file, ['jpg', 'jpeg', 'png', 'gif'], 4 * 1024 * 1024);
        if (!$check['ok']) {
            return ['success' => false, 'message' => $check['message']];
        }
        $ext = $check['ext'];
    } elseif ($ext === 'ico') {
        if (!isValidIcoFile($file['tmp_name'])) {
            return ['success' => false, 'message' => 'ICO 图标内容验证失败'];
        }
    } elseif ($ext === 'svg') {
        if (!isSafeSvgFile($file['tmp_name'])) {
            return ['success' => false, 'message' => 'SVG 含有不安全内容，已拒绝（请勿包含脚本、事件处理器或外部引用）'];
        }
    } else {
        return ['success' => false, 'message' => '不支持的文件格式'];
    }

    $filename = date('YmdHis') . '_' . uniqid() . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $absDir . $filename)) {
        return ['success' => true, 'path' => $relDir . $filename];
    }
    return ['success' => false, 'message' => '文件保存失败'];
}

/**
 * 检查频率限制
 * @return array ['allowed' => bool, 'wait_seconds' => int]
 */
function checkRateLimit($userId, $actionType, $seconds = 60) {
    // 管理员豁免
    if (isAdmin()) {
        return ['allowed' => true, 'wait_seconds' => 0];
    }
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT created_at FROM rate_limits WHERE user_id = ? AND action_type = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$userId, $actionType]);
    $row = $stmt->fetch();
    if ($row) {
        $lastTime = strtotime($row['created_at']);
        $elapsed = time() - $lastTime;
        if ($elapsed < $seconds) {
            return ['allowed' => false, 'wait_seconds' => $seconds - $elapsed];
        }
    }
    return ['allowed' => true, 'wait_seconds' => 0];
}

/**
 * 记录频率限制
 */
function recordRateLimit($userId, $actionType) {
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO rate_limits (user_id, action_type) VALUES (?, ?)");
    $stmt->execute([$userId, $actionType]);
}

// 文档背景图上传处理
function handleDocImageUpload($file) {
    $relDir = UPLOAD_PATH . 'documents/';
    $absDir = BASE_PATH . $relDir;
    if (!is_dir($absDir)) {
        mkdir($absDir, 0755, true);
    }
    $check = validateUploadedImageFile($file, ['jpg', 'jpeg', 'png', 'gif', 'webp'], 4 * 1024 * 1024);
    if (!$check['ok']) {
        return ['success' => false, 'message' => $check['message']];
    }
    $ext = $check['ext'];
    $filename = date('YmdHis') . '_' . uniqid() . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $absDir . $filename)) {
        return ['success' => true, 'path' => $relDir . $filename];
    }
    return ['success' => false, 'message' => '文件保存失败'];
}

/**
 * 发送站内信通知
 */
function sendNotification($userId, $title, $content, $linkUrl = null) {
    $pdo = getDB();
    $stmt = $pdo->prepare("INSERT INTO messages (user_id, title, content, link_url) VALUES (?, ?, ?, ?)");
    $stmt->execute([$userId, $title, $content, $linkUrl]);
}

function extractMentionUsernames($text) {
    $matches = [];
    preg_match_all('/(^|[^\x{4e00}-\x{9fa5}A-Za-z0-9_])@([\x{4e00}-\x{9fa5}A-Za-z0-9_]{2,30})(?![\x{4e00}-\x{9fa5}A-Za-z0-9_])/u', (string)$text, $matches);
    if (empty($matches[2])) {
        return [];
    }
    $usernames = array_values(array_unique(array_map('trim', $matches[2])));
    return array_slice($usernames, 0, 20);
}

function sendMentionNotifications($actorUserId, $actorUsername, $text, $contextLabel, $contextTitle, $linkUrl, $excludeUserIds = []) {
    $usernames = extractMentionUsernames($text);
    if (empty($usernames)) {
        return;
    }

    $excludeUserIds = array_values(array_unique(array_map('intval', array_merge($excludeUserIds, [$actorUserId]))));
    $pdo = getDB();
    $placeholders = implode(',', array_fill(0, count($usernames), '?'));
    $params = $usernames;
    $sql = "SELECT id, username FROM users WHERE username IN ($placeholders)";

    if (!empty($excludeUserIds)) {
        $excludePlaceholders = implode(',', array_fill(0, count($excludeUserIds), '?'));
        $sql .= " AND id NOT IN ($excludePlaceholders)";
        $params = array_merge($params, $excludeUserIds);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $mentionedUsers = $stmt->fetchAll();
    if (empty($mentionedUsers)) {
        return;
    }

    $safeTitle = trim((string)$contextTitle);
    if ($safeTitle === '') {
        $safeTitle = '相关内容';
    }
    $safeTitle = mb_substr($safeTitle, 0, 30);
    $contextPrefix = trim((string)$contextLabel) !== '' ? $contextLabel : '内容';
    $messageTitle = '有人@提到了您';

    foreach ($mentionedUsers as $mentionedUser) {
        sendNotification(
            (int)$mentionedUser['id'],
            $messageTitle,
            $actorUsername . ' 在' . $contextPrefix . '「' . $safeTitle . '」中提到了您',
            $linkUrl
        );
    }
}

/**
 * 兼容迁移：为历史“管理员须知”站内信补充跳转链接
 */

// ── 数据库迁移：集中调度（替代历史上分散在本文件中的 43 处内联调用）──
// 受 SCHEMA_VERSION 版本守卫：schema 已最新时直接跳过，避免每次请求重复 DDL 检查。
// 命令行可用 `php migrate.php`（或 `php migrate.php --force`）单独执行。
require_once __DIR__ . '/migrations/runner.php';
runSchemaMigrations();
?>
