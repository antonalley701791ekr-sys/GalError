<?php
function handleAdminSettingsChangePassword(PDO $pdo, int $adminId, array $input): array {
    $oldPassword = $input['old_password'] ?? '';
    $newPassword = $input['new_password'] ?? '';
    $confirmPassword = $input['confirm_password'] ?? '';
    if (empty($oldPassword) || empty($newPassword)) return ['message' => '请填写所有密码字段', 'messageType' => 'error'];
    if (mb_strlen($newPassword) < 8) return ['message' => '新密码长度不能少于 8 位', 'messageType' => 'error'];
    if ($newPassword !== $confirmPassword) return ['message' => '两次输入的新密码不一致', 'messageType' => 'error'];
    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?'); $stmt->execute([$adminId]); $admin = $stmt->fetch();
    if (!$admin || !password_verify($oldPassword, $admin['password'])) return ['message' => '旧密码错误', 'messageType' => 'error'];
    $hash = password_hash($newPassword, PASSWORD_DEFAULT); $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $adminId]);
    return ['message' => '密码修改成功', 'messageType' => 'success'];
}

function handleAdminSettingsChangeUsername(PDO $pdo, int $adminId, array $input): array {
    $newUsername = trim($input['new_username'] ?? '');
    if (empty($newUsername) || mb_strlen($newUsername) < 2 || mb_strlen($newUsername) > 30) return ['message' => '用户名长度需在 2-30 个字符之间', 'messageType' => 'error'];
    $stmt = $pdo->prepare('SELECT username_changes FROM users WHERE id = ?'); $stmt->execute([$adminId]); $admin = $stmt->fetch(); $usedChanges = $admin['username_changes'] ?? 0;
    if ($usedChanges >= 3) return ['message' => '您已达到最大修改次数（3次），无法继续修改用户名', 'messageType' => 'error'];
    $stmt = $pdo->prepare('SELECT COUNT(*) as count FROM users WHERE username = ? AND id != ?'); $stmt->execute([$newUsername, $adminId]); if ((int)$stmt->fetch()['count'] > 0) return ['message' => '该用户名已被使用', 'messageType' => 'error'];
    $pdo->prepare('UPDATE users SET username = ?, username_changes = username_changes + 1 WHERE id = ?')->execute([$newUsername, $adminId]); $_SESSION['admin_username'] = $newUsername;
    return ['message' => '用户名修改成功（已使用 ' . ($usedChanges + 1) . '/3 次修改机会）', 'messageType' => 'success'];
}

function handleAdminSettingsUploadAvatar(PDO $pdo, int $adminId, array $file): array {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return ['message' => '请选择头像文件', 'messageType' => 'error'];
    $result = handleAvatarUpload($file); if (!$result['success']) return ['message' => $result['message'], 'messageType' => 'error'];
    $stmt = $pdo->prepare('SELECT avatar FROM users WHERE id = ?'); $stmt->execute([$adminId]); $old = $stmt->fetch(); if ($old && $old['avatar'] && file_exists(BASE_PATH . $old['avatar'])) { @unlink(BASE_PATH . $old['avatar']); }
    $pdo->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$result['path'], $adminId]); $_SESSION['admin_avatar'] = $result['path'];
    return ['message' => '头像上传成功', 'messageType' => 'success'];
}

function handleAdminSettingsDeleteAvatar(PDO $pdo, int $adminId): array {
    $stmt = $pdo->prepare('SELECT avatar FROM users WHERE id = ?'); $stmt->execute([$adminId]); $admin = $stmt->fetch(); if ($admin && $admin['avatar'] && file_exists(BASE_PATH . $admin['avatar'])) { @unlink(BASE_PATH . $admin['avatar']); }
    $pdo->prepare('UPDATE users SET avatar = NULL WHERE id = ?')->execute([$adminId]); $_SESSION['admin_avatar'] = '';
    return ['message' => '头像已删除', 'messageType' => 'success'];
}
