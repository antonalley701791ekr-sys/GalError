<?php
require_once dirname(__DIR__) . '/includes/user_auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isUserLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

$query = trim($_GET['q'] ?? '');

if ($query !== '') {
    $query = preg_replace('/[^\x{4e00}-\x{9fa5}A-Za-z0-9_]/u', '', $query);
    if ($query === '') {
        echo json_encode(['success' => true, 'users' => []]);
        exit;
    }
}

$pdo = getDB();
$currentUserId = getCurrentUserId();

if ($query === '') {
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id != ? ORDER BY username ASC LIMIT 8");
    $stmt->execute([$currentUserId]);
} else {
    $like = $query . '%';
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id != ? AND username LIKE ? ORDER BY CASE WHEN username = ? THEN 0 ELSE 1 END, username ASC LIMIT 8");
    $stmt->execute([$currentUserId, $like, $query]);
}
$users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($users as &$user) {
    $user['id'] = (int)$user['id'];
}
unset($user);

echo json_encode(['success' => true, 'users' => $users], JSON_UNESCAPED_UNICODE);
