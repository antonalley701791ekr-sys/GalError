<?php
/**
 * 浏览量统计 API
 * POST /api/record_view.php
 * 参数: content_type (article/game/error/discussion), content_id, fingerprint (访客指纹)
 * 返回: JSON { success, user_views, guest_views }
 */

header('Content-Type: application/json; charset=utf-8');

// 仅允许 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'code' => 'method_not_allowed', 'message' => '请求方法不允许']);
    exit;
}

require_once dirname(__DIR__) . '/includes/user_auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/api_response.php';
require_once dirname(__DIR__) . '/includes/api_security.php';

api_require_basic_auth('record_view');
api_enforce_rate_limit('record_view', 80, 60);

$pdo = getDB();

function isSameOriginApiRequest(): bool {
    $serverHost = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($serverHost === '') {
        return false;
    }

    // HTTP_HOST 可能包含端口，统一剥离后再比较
    $normalizedServerHost = preg_replace('/:\\d+$/', '', $serverHost);
    if (!is_string($normalizedServerHost) || $normalizedServerHost === '') {
        return false;
    }

    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '') {
        $originHost = parse_url($origin, PHP_URL_HOST);
        return is_string($originHost) && strcasecmp($originHost, $normalizedServerHost) === 0;
    }

    $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($referer !== '') {
        $refererHost = parse_url($referer, PHP_URL_HOST);
        return is_string($refererHost) && strcasecmp($refererHost, $normalizedServerHost) === 0;
    }

    // 某些浏览器/隐私插件会移除 Origin/Referer，已开启 CSRF 校验时允许放行
    return true;
}

// 轻量限频：同一 IP 对同一内容，1 分钟最多 30 次
function viewRateLimitExceeded(string $ip, string $contentType, int $contentId, int $max = 30, int $window = 60): bool {
    $baseDir = BASE_PATH . UPLOAD_PATH . 'cache/';
    if (!is_dir($baseDir)) {
        @mkdir($baseDir, 0755, true);
    }
    $file = $baseDir . 'view_rate_limit.json';

    $now = time();
    $key = hash('sha256', $ip . '|' . $contentType . '|' . $contentId);

    $data = [];
    if (is_file($file)) {
        $json = @file_get_contents($file);
        $decoded = json_decode((string)$json, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    // 清理过期窗口
    foreach ($data as $k => $row) {
        $ts = intval($row['ts'] ?? 0);
        if ($ts <= 0 || ($now - $ts) > $window) {
            unset($data[$k]);
        }
    }

    $row = $data[$key] ?? ['count' => 0, 'ts' => $now];
    $count = intval($row['count'] ?? 0);
    $ts = intval($row['ts'] ?? $now);

    if (($now - $ts) > $window) {
        $count = 0;
        $ts = $now;
    }

    $count++;
    $data[$key] = ['count' => $count, 'ts' => $ts];
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));

    return $count > $max;
}

// 获取参数
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$contentType = trim((string)($input['content_type'] ?? ''));
$contentId = intval($input['content_id'] ?? 0);
$fingerprint = trim((string)($input['fingerprint'] ?? ''));

// 验证参数
$validTypes = ['article', 'game', 'error', 'discussion'];
if (!in_array($contentType, $validTypes, true) || $contentId <= 0) {
    echo json_encode(['success' => false, 'code' => 'invalid_params', 'message' => '参数错误']);
    exit;
}

if ($fingerprint !== '' && (strlen($fingerprint) > 128 || !preg_match('/^[a-zA-Z0-9_\-:.]+$/', $fingerprint))) {
    echo json_encode(['success' => false, 'code' => 'invalid_fingerprint', 'message' => 'fingerprint 非法']);
    exit;
}

// API 调用来源校验（阻止跨站滥用）
if (!isSameOriginApiRequest()) {
    $logLine = '[' . date('Y-m-d H:i:s') . '] record_view bad_origin; host=' . ($_SERVER['HTTP_HOST'] ?? '')
        . '; origin=' . ($_SERVER['HTTP_ORIGIN'] ?? '')
        . '; referer=' . ($_SERVER['HTTP_REFERER'] ?? '')
        . '; uri=' . ($_SERVER['REQUEST_URI'] ?? '') . "\n";
    @file_put_contents(BASE_PATH . UPLOAD_PATH . 'view_counter_debug.log', $logLine, FILE_APPEND);
    http_response_code(403);
    echo json_encode(['success' => false, 'code' => 'bad_origin', 'message' => '非法来源']);
    exit;
}

// CSRF 校验：浏览统计为低风险接口，优先保障可用性
$hasAnyCsrfToken =
    (!empty($_POST['_csrf']) && is_string($_POST['_csrf']))
    || (!empty($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN']))
    || (!empty($_POST['_csrf_header_token']) && is_string($_POST['_csrf_header_token']));

if ($hasAnyCsrfToken && !csrf_validate_request_flexible(['default', 'admin_form'])) {
    $logLine = '[' . date('Y-m-d H:i:s') . '] record_view csrf_warn_only; has_post_csrf=' . (isset($_POST['_csrf']) ? '1' : '0')
        . '; has_header_csrf=' . (!empty($_SERVER['HTTP_X_CSRF_TOKEN']) ? '1' : '0')
        . '; has_fallback_csrf=' . (!empty($_POST['_csrf_header_token']) ? '1' : '0')
        . '; type=' . $contentType . '; id=' . $contentId . "\n";
    @file_put_contents(BASE_PATH . UPLOAD_PATH . 'view_counter_debug.log', $logLine, FILE_APPEND);
}

// 判断用户状态
$isLoggedIn = isUserLoggedIn();
$userId = getCurrentUserId();
$clientIP = getClientIP();

if (viewRateLimitExceeded((string)$clientIP, $contentType, $contentId, 30, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'code' => 'rate_limited', 'message' => '请求过于频繁，请稍后再试']);
    exit;
}

// 生成访客标识
if ($isLoggedIn && $userId) {
    $visitorType = 'user';
    $visitorId = (string)$userId;
} else {
    $visitorType = 'guest';
    // IP + 指纹组合去重
    $fp = $fingerprint ?: 'nofp';
    $visitorId = hash('sha256', $clientIP . '|' . $fp);
}

// 检查24小时内是否已记录
$stmt = $pdo->prepare("\n    SELECT id FROM view_logs \n    WHERE content_type = ? AND content_id = ? AND visitor_type = ? AND visitor_id = ? \n    AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)\n    LIMIT 1\n");
$stmt->execute([$contentType, $contentId, $visitorType, $visitorId]);

if ($stmt->rowCount() === 0) {
    // 未记录过，新增日志并更新计数
    try {
        $pdo->beginTransaction();

        // 插入去重日志
        $stmt = $pdo->prepare("INSERT INTO view_logs (content_type, content_id, visitor_type, visitor_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$contentType, $contentId, $visitorType, $visitorId]);

        // 更新或插入浏览量计数
        $field = ($visitorType === 'user') ? 'user_views' : 'guest_views';
        $allowedFields = ['user_views', 'guest_views'];
        if (!in_array($field, $allowedFields, true)) {
            throw new Exception('Invalid field');
        }
        $stmt = $pdo->prepare("\n            INSERT INTO view_counts (content_type, content_id, {$field}, last_viewed_at)\n            VALUES (?, ?, 1, NOW())\n            ON DUPLICATE KEY UPDATE {$field} = {$field} + 1, last_viewed_at = NOW()\n        ");
        $stmt->execute([$contentType, $contentId]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $logLine = '[' . date('Y-m-d H:i:s') . '] record_view db_error; type=' . $contentType . '; id=' . $contentId
            . '; visitor_type=' . $visitorType . '; msg=' . $e->getMessage() . "\n";
        @file_put_contents(BASE_PATH . UPLOAD_PATH . 'view_counter_debug.log', $logLine, FILE_APPEND);
        echo json_encode(['success' => false, 'message' => '统计失败']);
        exit;
    }
}

// 返回当前浏览量
$counts = getViewCount($contentType, $contentId);
echo json_encode([
    'success' => true,
    'user_views' => $counts['user_views'],
    'guest_views' => $counts['guest_views'],
    'total_views' => $counts['total_views']
]);
