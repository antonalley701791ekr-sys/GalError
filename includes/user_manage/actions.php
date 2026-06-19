<?php
function handleUserManageOnlineSnapshot(PDO $pdo, array $input): array {
    $onlineTimeoutMinutes = defined('ONLINE_TIMEOUT_MINUTES') ? max(1, (int)ONLINE_TIMEOUT_MINUTES) : 10;
    $onlineTimeoutSeconds = $onlineTimeoutMinutes * 60;

    $rawIds = $input['user_ids'] ?? [];
    $userIds = [];
    if (is_array($rawIds)) {
        foreach ($rawIds as $id) {
            $id = intval($id);
            if ($id > 0) $userIds[$id] = $id;
        }
    }

    $statuses = [];
    if (!empty($userIds)) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare("SELECT id, UNIX_TIMESTAMP(last_activity_at) AS last_activity_ts FROM users WHERE id IN ($placeholders)");
        $stmt->execute(array_values($userIds));
        $rows = $stmt->fetchAll();
        $onlineThreshold = time() - $onlineTimeoutSeconds;
        foreach ($rows as $row) {
            $ts = !empty($row['last_activity_ts']) ? (int)$row['last_activity_ts'] : 0;
            $statuses[(int)$row['id']] = ['last_activity_ts' => $ts, 'is_online' => ($ts > 0 && $ts >= $onlineThreshold)];
        }
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE enabled = 1 AND last_activity_at IS NOT NULL AND last_activity_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)");
    $countStmt->execute([$onlineTimeoutMinutes]);

    return ['success' => true, 'server_now_ts' => time(), 'timeout_seconds' => $onlineTimeoutSeconds, 'online_users_count' => (int)$countStmt->fetchColumn(), 'statuses' => $statuses];
}

function handleUserManageVerifyEmail(PDO $pdo, array $input): array {
    if (!isSuperAdmin()) return ['success' => false, 'message' => '此功能仅超级管理员可访问'];
    $lastVerify = $_SESSION['last_verify_time'] ?? 0;
    if (time() - $lastVerify < 10) return ['success' => false, 'message' => '操作过于频繁，请稍后再试'];
    $userId = intval($input['user_id'] ?? 0);
    if ($userId <= 0) return ['success' => false, 'message' => '无效的用户ID'];
    $stmt = $pdo->prepare("SELECT id, username, email, role, email_verified FROM users WHERE id = ?"); $stmt->execute([$userId]); $target = $stmt->fetch();
    if (!$target) return ['success' => false, 'message' => '用户不存在'];
    if (!in_array((string)$target['role'], ['user', 'sub'], true)) return ['success' => false, 'message' => '不能验证该账户'];
    if ($target['email_verified']) return ['success' => false, 'message' => '该用户已经验证过了'];
    $upStmt = $pdo->prepare("UPDATE users SET email_verified = 1, verify_token = NULL, verify_token_expires = NULL WHERE id = ? AND email_verified = 0"); $upStmt->execute([$userId]);
    if ($upStmt->rowCount() === 0) return ['success' => false, 'message' => '操作未生效，用户可能已被其他管理员验证'];
    $mailSent = !empty($target['email']) ? sendAccountVerifiedEmail($target['email'], $target['username']) : false;
    $adminId = $_SESSION['admin_id'] ?? 0; $adminUsername = $_SESSION['admin_username'] ?? 'unknown';
    $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, admin_username, action, target_user_id, target_username, detail, result, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $logStmt->execute([$adminId, $adminUsername, 'manual_verify_email', $target['id'], $target['username'], json_encode(['email' => maskEmail($target['email']), 'mail_sent' => $mailSent], JSON_UNESCAPED_UNICODE), 'success', getClientIP()]);
    $_SESSION['last_verify_time'] = time();
    return ['success' => true, 'message' => '验证成功，用户已激活'];
}

function handleUserManageBatchVerify(PDO $pdo, array $input): array {
    if (!isSuperAdmin()) return ['success' => false, 'message' => '此功能仅超级管理员可访问'];
    $lastVerify = $_SESSION['last_verify_time'] ?? 0;
    if (time() - $lastVerify < 10) return ['success' => false, 'message' => '操作过于频繁，请稍后再试'];
    $userIds = $input['user_ids'] ?? [];
    if (!is_array($userIds) || empty($userIds)) return ['success' => false, 'message' => '请选择要验证的用户'];
    if (count($userIds) > 50) return ['success' => false, 'message' => '单次最多验证 50 个用户'];
    $adminId = $_SESSION['admin_id'] ?? 0; $adminUsername = $_SESSION['admin_username'] ?? 'unknown'; $verified = 0; $skipped = 0; $mailQueue = [];
    $selectStmt = $pdo->prepare("SELECT id, username, email, role, email_verified FROM users WHERE id = ?");
    $upStmt = $pdo->prepare("UPDATE users SET email_verified = 1, verify_token = NULL, verify_token_expires = NULL WHERE id = ? AND email_verified = 0");
    $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, admin_username, action, target_user_id, target_username, detail, result, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $pdo->beginTransaction();
    try {
        foreach ($userIds as $uid) {
            $uid = intval($uid); if ($uid <= 0) { $skipped++; continue; }
            $selectStmt->execute([$uid]); $target = $selectStmt->fetch();
            if (!$target || $target['role'] !== 'user' || $target['email_verified']) { $skipped++; continue; }
            $upStmt->execute([$uid]); if ($upStmt->rowCount() === 0) { $skipped++; continue; }
            if (!empty($target['email'])) $mailQueue[] = ['email' => $target['email'], 'username' => $target['username']];
            $logStmt->execute([$adminId, $adminUsername, 'batch_verify_email', $target['id'], $target['username'], json_encode(['email' => maskEmail($target['email'])], JSON_UNESCAPED_UNICODE), 'success', getClientIP()]);
            $verified++;
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => '数据库操作失败，请重试'];
    }
    foreach ($mailQueue as $m) sendAccountVerifiedEmail($m['email'], $m['username']);
    $_SESSION['last_verify_time'] = time();
    return ['success' => true, 'message' => "批量验证完成：成功 {$verified} 个，跳过 {$skipped} 个", 'verified' => $verified, 'skipped' => $skipped];
}

function handleUserManageDeleteUser(PDO $pdo, array $input): array {
    if (!isSuperAdmin()) return ['success' => false, 'message' => '此功能仅超级管理员可访问'];
    $userId = intval($input['user_id'] ?? 0);
    if ($userId <= 0) return ['success' => false, 'message' => '无效的用户ID'];
    $stmt = $pdo->prepare("SELECT id, username, email, role, avatar FROM users WHERE id = ?"); $stmt->execute([$userId]); $target = $stmt->fetch();
    if (!$target) return ['success' => false, 'message' => '用户不存在'];
    if ($target['role'] !== 'user') return ['success' => false, 'message' => '不能删除管理员账户，请先撤销管理员权限'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM messages WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("DELETE FROM articles WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("UPDATE errors SET user_id = NULL WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("UPDATE games SET user_id = NULL WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("UPDATE error_revisions SET user_id = NULL WHERE user_id = ?")->execute([$userId]);
        revokeRememberMeTokensByUserId($userId);
        $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'user'")->execute([$userId]);
        $adminId = $_SESSION['admin_id'] ?? 0; $adminUsername = $_SESSION['admin_username'] ?? 'unknown';
        $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, admin_username, action, target_user_id, target_username, detail, result, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $logStmt->execute([$adminId, $adminUsername, 'delete_user', $target['id'], $target['username'], json_encode(['email' => maskEmail($target['email'])], JSON_UNESCAPED_UNICODE), 'success', getClientIP()]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => '删除失败：数据库操作出错'];
    }
    if (!empty($target['avatar'])) { $avatarPath = BASE_PATH . $target['avatar']; if (file_exists($avatarPath)) @unlink($avatarPath); }
    return ['success' => true, 'message' => '用户【' . $target['username'] . '】已成功删除'];
}

function handleUserManageUpgradeToSub(PDO $pdo, array $input, array $permModules): array {
    if (!isSuperAdmin()) return ['success' => false, 'message' => '此功能仅超级管理员可访问'];
    $userId = intval($input['user_id'] ?? 0); $rawPerms = $input['permissions'] ?? [];
    if ($userId <= 0) return ['success' => false, 'message' => '无效的用户ID'];
    if (!is_array($rawPerms) || empty($rawPerms)) return ['success' => false, 'message' => '请至少选择一项权限'];
    $stmt = $pdo->prepare("SELECT id, username, email, role, banned FROM users WHERE id = ?"); $stmt->execute([$userId]); $target = $stmt->fetch();
    if (!$target) return ['success' => false, 'message' => '用户不存在'];
    if ($target['role'] !== 'user') return ['success' => false, 'message' => '该用户不是普通用户，无法执行此操作'];
    if (!empty($target['banned'])) return ['success' => false, 'message' => '该用户已被封禁，请先解封再升级'];
    $cleanPerms = [];
    foreach ($rawPerms as $mod => $actions) {
        if (!isset($permModules[$mod]) || !is_array($actions)) continue;
        $valid = [];
        foreach ($actions as $act) if (in_array($act, $permModules[$mod]['actions'], true)) $valid[] = $act;
        if (!empty($valid)) $cleanPerms[$mod] = $valid;
    }
    if (empty($cleanPerms)) return ['success' => false, 'message' => '请至少选择一项有效权限'];
    $permJson = json_encode($cleanPerms, JSON_UNESCAPED_UNICODE);
    $pdo->beginTransaction();
    try {
        $upStmt = $pdo->prepare("UPDATE users SET role = 'sub', permissions = ?, banned = 0, enabled = 1 WHERE id = ? AND role = 'user'");
        $upStmt->execute([$permJson, $userId]);
        if ($upStmt->rowCount() === 0) { $pdo->rollBack(); return ['success' => false, 'message' => '操作未生效，用户状态可能已变更']; }
        $adminId = $_SESSION['admin_id'] ?? 0; $adminUsername = $_SESSION['admin_username'] ?? 'unknown';
        $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, admin_username, action, target_user_id, target_username, detail, result, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $logStmt->execute([$adminId, $adminUsername, 'upgrade_to_sub_admin', $target['id'], $target['username'], json_encode(['email' => maskEmail($target['email']), 'permissions' => $cleanPerms], JSON_UNESCAPED_UNICODE), 'success', getClientIP()]);
        $pdo->commit();
        sendNotification($userId, '恭喜成为管理员！请阅读管理员须知', '您已被提升为管理员，请务必阅读管理员须知，了解管理职责和操作规范。');
    } catch (Exception $e) {
        $pdo->rollBack(); return ['success' => false, 'message' => '操作失败：数据库错误'];
    }
    return ['success' => true, 'message' => '用户【' . $target['username'] . '】已成功升级为子管理员'];
}

function handleUserManageRevokeAdmin(PDO $pdo, array $input): array {
    if (!isSuperAdmin()) return ['success' => false, 'message' => '此功能仅超级管理员可访问'];
    $userId = intval($input['user_id'] ?? 0);
    if ($userId <= 0) return ['success' => false, 'message' => '无效的用户ID'];
    $stmt = $pdo->prepare("SELECT id, username, role, permissions FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $target = $stmt->fetch();
    if (!$target) return ['success' => false, 'message' => '用户不存在'];
    if ((string)$target['role'] !== 'sub') return ['success' => false, 'message' => '该用户不是子管理员'];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE users SET role = 'user', permissions = NULL WHERE id = ? AND role = 'sub'")->execute([$userId]);
        $adminId = $_SESSION['admin_id'] ?? 0; $adminUsername = $_SESSION['admin_username'] ?? 'unknown';
        $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, admin_username, action, target_user_id, target_username, detail, result, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $logStmt->execute([$adminId, $adminUsername, 'revoke_admin_permission', $target['id'], $target['username'], json_encode(['from_role' => 'sub', 'to_role' => 'user'], JSON_UNESCAPED_UNICODE), 'success', getClientIP()]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => '撤销失败：数据库错误'];
    }
    return ['success' => true, 'message' => '已撤销管理员权限，用户现在是普通用户'];
}

function handleUserManageResetPassword(PDO $pdo, array $input): array {
    if (!isSuperAdmin()) return ['success' => false, 'message' => '此功能仅超级管理员可访问'];
    $userId = intval($input['user_id'] ?? 0);
    if ($userId <= 0) return ['success' => false, 'message' => '无效的用户ID'];
    $stmt = $pdo->prepare("SELECT id, username, email, role FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $target = $stmt->fetch();
    if (!$target) return ['success' => false, 'message' => '用户不存在'];
    $newPassword = bin2hex(random_bytes(4));
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $up->execute([$hash, $userId]);
        $adminId = $_SESSION['admin_id'] ?? 0; $adminUsername = $_SESSION['admin_username'] ?? 'unknown';
        $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, admin_username, action, target_user_id, target_username, detail, result, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $logStmt->execute([$adminId, $adminUsername, 'reset_user_password', $target['id'], $target['username'], json_encode(['email' => maskEmail((string)($target['email'] ?? ''))], JSON_UNESCAPED_UNICODE), 'success', getClientIP()]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => '重置失败：数据库错误'];
    }
    $mailSent = false;
    if (!empty($target['email'])) {
        $mailSent = sendAccountVerifiedEmail($target['email'], $target['username']);
    }
    return ['success' => true, 'message' => '密码已重置，新密码：' . $newPassword . ($mailSent ? '（已发送邮件）' : '')];
}

function handleUserManageBanUser(PDO $pdo, array $input): array {
    if (!isSuperAdmin()) return ['success' => false, 'message' => '此功能仅超级管理员可访问'];
    $userId = intval($input['user_id'] ?? 0);
    if ($userId <= 0) return ['success' => false, 'message' => '无效的用户ID'];
    $stmt = $pdo->prepare("SELECT id, username, role, banned FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $target = $stmt->fetch();
    if (!$target) return ['success' => false, 'message' => '用户不存在'];
    if ((string)$target['role'] === 'super') return ['success' => false, 'message' => '不能封禁超级管理员'];
    $pdo->prepare("UPDATE users SET banned = 1, enabled = 0 WHERE id = ?")->execute([$userId]);
    return ['success' => true, 'message' => '用户已封禁'];
}

function handleUserManageUnbanUser(PDO $pdo, array $input): array {
    if (!isSuperAdmin()) return ['success' => false, 'message' => '此功能仅超级管理员可访问'];
    $userId = intval($input['user_id'] ?? 0);
    if ($userId <= 0) return ['success' => false, 'message' => '无效的用户ID'];
    $stmt = $pdo->prepare("SELECT id, username, role, banned FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $target = $stmt->fetch();
    if (!$target) return ['success' => false, 'message' => '用户不存在'];
    if ((string)$target['role'] === 'super') return ['success' => false, 'message' => '不能解封超级管理员'];
    $pdo->prepare("UPDATE users SET banned = 0, enabled = 1 WHERE id = ?")->execute([$userId]);
    return ['success' => true, 'message' => '用户已解封'];
}

function handleUserManageVerifyAdminEmail(PDO $pdo, array $input): array {
    if (!isSuperAdmin()) return ['success' => false, 'message' => '此功能仅超级管理员可访问'];
    $lastVerify = $_SESSION['last_admin_verify_time'] ?? 0;
    if (time() - $lastVerify < 10) return ['success' => false, 'message' => '操作过于频繁，请稍后再试'];
    $adminTargetId = intval($input['admin_id'] ?? 0);
    if ($adminTargetId <= 0) return ['success' => false, 'message' => '无效的管理员ID'];
    $stmt = $pdo->prepare("SELECT id, username, email, role, email_verified FROM users WHERE id = ?"); $stmt->execute([$adminTargetId]); $target = $stmt->fetch();
    if (!$target) return ['success' => false, 'message' => '用户不存在'];
    if (!in_array($target['role'], ['sub', 'super'])) return ['success' => false, 'message' => '该用户不是管理员'];
    if (empty($target['email'])) return ['success' => false, 'message' => '该管理员未设置邮箱'];
    if ($target['email_verified']) return ['success' => false, 'message' => '该管理员邮箱已经验证过了'];
    $upStmt = $pdo->prepare("UPDATE users SET email_verified = 1, verify_token = NULL, verify_token_expires = NULL WHERE id = ? AND email_verified = 0"); $upStmt->execute([$adminTargetId]);
    if ($upStmt->rowCount() === 0) return ['success' => false, 'message' => '操作未生效，可能已被其他管理员验证'];
    $mailSent = sendAccountVerifiedEmail($target['email'], $target['username']);
    $adminId = $_SESSION['admin_id'] ?? 0; $adminUsername = $_SESSION['admin_username'] ?? 'unknown';
    $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, admin_username, action, target_user_id, target_username, detail, result, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $logStmt->execute([$adminId, $adminUsername, 'manual_verify_admin_email', $target['id'], $target['username'], json_encode(['email' => maskEmail($target['email']), 'mail_sent' => $mailSent], JSON_UNESCAPED_UNICODE), 'success', getClientIP()]);
    $_SESSION['last_admin_verify_time'] = time();
    return ['success' => true, 'message' => '验证成功，管理员邮箱已激活'];
}
