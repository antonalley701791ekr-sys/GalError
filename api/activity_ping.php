<?php
require_once dirname(__DIR__) . '/includes/user_auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '请求方法不允许']);
    exit;
}

if (!isUserLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

updateCurrentUserActivity(false);

echo json_encode([
    'success' => true,
    'server_now_ts' => time(),
]);
