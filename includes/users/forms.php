<?php
function usersHandleModerationAction(PDO $pdo, string $action, int $id): array {
    $stmt = $pdo->prepare("SELECT id, username, role, enabled FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) return ['message' => '管理员不存在', 'messageType' => 'error'];
    if (!in_array((string)$target['role'], ['sub', 'super'], true)) return ['message' => '目标用户不是管理员', 'messageType' => 'error'];
    if ($action === 'ban_user') {
        $pdo->prepare("UPDATE users SET enabled = 0, banned = 1 WHERE id = ?")->execute([$id]);
        return ['message' => '已禁用管理员', 'messageType' => 'success'];
    }
    if ($action === 'unban_user') {
        $pdo->prepare("UPDATE users SET enabled = 1, banned = 0 WHERE id = ?")->execute([$id]);
        return ['message' => '已启用管理员', 'messageType' => 'success'];
    }
    if ($action === 'revoke_admin') {
        if ((string)$target['role'] !== 'sub') return ['message' => '只有子管理员可撤销权限', 'messageType' => 'error'];
        $pdo->prepare("UPDATE users SET role = 'user', permissions = NULL WHERE id = ? AND role = 'sub'")->execute([$id]);
        return ['message' => '已撤销管理员权限', 'messageType' => 'success'];
    }
    return ['message' => '未知操作', 'messageType' => 'error'];
}

function usersGetFormContext(PDO $pdo, string $action, ?int $id = null): array {
    $editAdmin = null;
    $resetAdmin = null;
    if ($action === 'edit' && $id) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $editAdmin = $stmt->fetch();
        if ($editAdmin) {
            $editAdmin['_perms'] = json_decode($editAdmin['permissions'] ?? '{}', true) ?: [];
        }
    }
    if ($action === 'reset_password' && $id) {
        $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $resetAdmin = $stmt->fetch();
    }
    return compact('editAdmin', 'resetAdmin');
}
