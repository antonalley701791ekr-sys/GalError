<?php
require_once dirname(__DIR__) . '/includes/user_auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支持的请求方法']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['action'])) {
    echo json_encode(['success' => false, 'message' => '参数错误']);
    exit;
}

$pdo = getDB();
$action = $input['action'];

// ========== 删除话题 ==========
if ($action === 'delete') {
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限']);
        exit;
    }

    $discId = intval($input['id'] ?? 0);
    if ($discId <= 0) {
        echo json_encode(['success' => false, 'message' => '无效的话题ID']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE discussions SET status = 'deleted' WHERE id = ?");
    $result = $stmt->execute([$discId]);

    echo json_encode(['success' => $result ? true : false]);
    exit;
}

echo json_encode(['success' => false, 'message' => '未知操作']);
