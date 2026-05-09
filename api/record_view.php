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
    echo json_encode(['success' => false, 'message' => '请求方法不允许']);
    exit;
}

require_once dirname(__DIR__) . '/includes/user_auth.php';

$pdo = getDB();

// 获取参数
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$contentType = $input['content_type'] ?? '';
$contentId = intval($input['content_id'] ?? 0);
$fingerprint = trim($input['fingerprint'] ?? '');

// 验证参数
$validTypes = ['article', 'game', 'error', 'discussion'];
if (!in_array($contentType, $validTypes, true) || $contentId <= 0) {
    echo json_encode(['success' => false, 'message' => '参数错误']);
    exit;
}

// 判断用户状态
$isLoggedIn = isUserLoggedIn();
$userId = getCurrentUserId();
$clientIP = getClientIP();

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
$stmt = $pdo->prepare("
    SELECT id FROM view_logs 
    WHERE content_type = ? AND content_id = ? AND visitor_type = ? AND visitor_id = ? 
    AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    LIMIT 1
");
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
        $stmt = $pdo->prepare("
            INSERT INTO view_counts (content_type, content_id, {$field}, last_viewed_at)
            VALUES (?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE {$field} = {$field} + 1, last_viewed_at = NOW()
        ");
        $stmt->execute([$contentType, $contentId]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
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
