<?php
require_once 'includes/user_auth.php';
require_once 'includes/view.php';
require_once 'includes/discussion/service.php';

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /discussions');
    exit;
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT d.id, d.user_id, d.status, d.title, u.username FROM discussions d JOIN users u ON d.user_id = u.id WHERE d.id = ? LIMIT 1");
$stmt->execute([$id]);
$discussion = $stmt->fetch();

if (!$discussion || $discussion['status'] !== 'active') {
    http_response_code(404);
    echo '话题不存在或已被删除';
    exit;
}

$currentUserId = (int)getCurrentUserId();
$isOwner = $currentUserId > 0 && $currentUserId === (int)$discussion['user_id'];
if (!isUserLoggedIn() || (!isAdmin() && !$isOwner)) {
    http_response_code(403);
    echo '没有权限删除这个话题';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $csrf = (string)($_GET['_csrf'] ?? '');
    if ($csrf === '' || !csrf_validate($csrf, 'default')) {
        $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    }
    if ($csrf === '' || !csrf_validate($csrf, 'default')) {
        http_response_code(403);
        echo '请求已过期，请刷新后重试';
        exit;
    }
}

try {
    $pdo->beginTransaction();

    $pdo->prepare("UPDATE discussions SET status = 'deleted' WHERE id = ?")->execute([$id]);
    $pdo->prepare("UPDATE comments SET status = 'deleted' WHERE content_type = 'discussion' AND content_id = ?")->execute([$id]);

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo '删除失败，请稍后重试';
    exit;
}

$redirect = '/discussions';
header('Location: ' . $redirect);
exit;
