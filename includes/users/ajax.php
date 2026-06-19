<?php
function usersHandleAjax(PDO $pdo): void {
    $action = $_POST['action'] ?? ($_GET['action'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $action !== 'verify_admin_email') {
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    $lastVerify = $_SESSION['last_admin_verify_time'] ?? 0;
    if (time() - $lastVerify < 10) {
        echo json_encode(['success' => false, 'message' => '操作过于频繁，请稍后再试']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $adminId = intval($input['admin_id'] ?? 0);
    if ($adminId <= 0) { echo json_encode(['success' => false, 'message' => '无效的管理员ID']); exit; }

    $stmt = $pdo->prepare("SELECT id, username, email, role, email_verified FROM users WHERE id = ?");
    $stmt->execute([$adminId]);
    $target = $stmt->fetch();
    if (!$target) { echo json_encode(['success' => false, 'message' => '用户不存在']); exit; }
    if (!in_array($target['role'], ['sub', 'super'])) { echo json_encode(['success' => false, 'message' => '该用户不是管理员']); exit; }
    if (empty($target['email'])) { echo json_encode(['success' => false, 'message' => '该管理员未设置邮箱']); exit; }
    if ($target['email_verified']) { echo json_encode(['success' => false, 'message' => '该管理员邮箱已经验证过了']); exit; }

    $upStmt = $pdo->prepare("UPDATE users SET email_verified = 1, verify_token = NULL, verify_token_expires = NULL WHERE id = ? AND email_verified = 0");
    $upStmt->execute([$adminId]);
    if ($upStmt->rowCount() === 0) { echo json_encode(['success' => false, 'message' => '操作未生效，可能已被其他管理员验证']); exit; }

    $mailSent = !empty($target['email']) ? sendAccountVerifiedEmail($target['email'], $target['username']) : false;
    $logAdminId = $_SESSION['admin_id'] ?? 0;
    $logAdminUsername = $_SESSION['admin_username'] ?? 'unknown';
    $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, admin_username, action, target_user_id, target_username, detail, result, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $logStmt->execute([$logAdminId, $logAdminUsername, 'manual_verify_admin_email', $target['id'], $target['username'], json_encode(['email' => maskEmail($target['email']), 'mail_sent' => $mailSent], JSON_UNESCAPED_UNICODE), 'success', getClientIP()]);

    $_SESSION['last_admin_verify_time'] = time();
    echo json_encode(['success' => true, 'message' => '验证成功，管理员邮箱已激活']);
    exit;
}
