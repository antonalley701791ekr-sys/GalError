<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

checkLogin();

$pdo = getDB();
$adminId = $_SESSION['admin_id'];
$message = '';
$messageType = '';

$action = $_POST['action'] ?? '';

// 修改密码
if ($action === 'change_password') {
    $oldPassword = $_POST['old_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($oldPassword) || empty($newPassword)) {
        $message = '请填写所有密码字段';
        $messageType = 'error';
    } elseif (mb_strlen($newPassword) < 8) {
        $message = '新密码长度不能少于 8 位';
        $messageType = 'error';
    } elseif ($newPassword !== $confirmPassword) {
        $message = '两次输入的新密码不一致';
        $messageType = 'error';
    } else {
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch();
        if (!$admin || !password_verify($oldPassword, $admin['password'])) {
            $message = '旧密码错误';
            $messageType = 'error';
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $adminId]);
            $message = '密码修改成功';
            $messageType = 'success';
        }
    }
}

// 修改用户名
if ($action === 'change_username') {
    $newUsername = trim($_POST['new_username'] ?? '');
    if (empty($newUsername) || mb_strlen($newUsername) < 2 || mb_strlen($newUsername) > 30) {
        $message = '用户名长度需在 2-30 个字符之间';
        $messageType = 'error';
    } else {
        $stmt = $pdo->prepare("SELECT username_changes FROM users WHERE id = ?");
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch();
        $usedChanges = $admin['username_changes'] ?? 0;
        if ($usedChanges >= 3) {
            $message = '您已达到最大修改次数（3次），无法继续修改用户名';
            $messageType = 'error';
        } else {
            // 唯一性检查
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$newUsername, $adminId]);
            if ($stmt->fetch()['count'] > 0) {
                $message = '该用户名已被使用';
                $messageType = 'error';
            } else {
                $pdo->prepare("UPDATE users SET username = ?, username_changes = username_changes + 1 WHERE id = ?")
                    ->execute([$newUsername, $adminId]);
                $_SESSION['admin_username'] = $newUsername;
                $message = '用户名修改成功（已使用 ' . ($usedChanges + 1) . '/3 次修改机会）';
                $messageType = 'success';
            }
        }
    }
}

// 上传头像
if ($action === 'upload_avatar') {
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        $result = handleAvatarUpload($_FILES['avatar']);
        if ($result['success']) {
            // 删除旧头像
            $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
            $stmt->execute([$adminId]);
            $old = $stmt->fetch();
            if ($old && $old['avatar'] && file_exists(BASE_PATH . $old['avatar'])) {
                unlink(BASE_PATH . $old['avatar']);
            }
            $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?")->execute([$result['path'], $adminId]);
            $_SESSION['admin_avatar'] = $result['path'];
            $message = '头像上传成功';
            $messageType = 'success';
        } else {
            $message = $result['message'];
            $messageType = 'error';
        }
    } else {
        $message = '请选择头像文件';
        $messageType = 'error';
    }
}

// 删除头像
if ($action === 'delete_avatar') {
    $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch();
    if ($admin && $admin['avatar'] && file_exists(BASE_PATH . $admin['avatar'])) {
        unlink(BASE_PATH . $admin['avatar']);
    }
    $pdo->prepare("UPDATE users SET avatar = NULL WHERE id = ?")->execute([$adminId]);
    $_SESSION['admin_avatar'] = '';
    $message = '头像已删除';
    $messageType = 'success';
}

// 获取当前管理员信息
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$adminId]);
$currentAdmin = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>个人设置 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <?php renderAdminHeadScript(); ?>
</head>
<body class="has-fixed-nav">
    <?php include '../includes/header.php'; ?>
    <div class="admin-layout">
        <?php renderAdminSidebar('admin_settings.php'); ?>

        <div class="admin-content">
            <main class="admin-main">
                <div class="admin-page-header">
                    <h1>个人设置</h1>
                </div>
                <?php if ($message): ?>
                    <div class="admin-alert-<?php echo $messageType; ?>">
                        <?php echo h($message); ?>
                    </div>
                <?php endif; ?>

                <!-- 头像设置 -->
                <div class="card settings-section">
                    <div class="card-header">头像设置</div>
                    <div class="card-body">
                        <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 20px;">
                            <?php if (!empty($currentAdmin['avatar']) && file_exists(BASE_PATH . $currentAdmin['avatar'])): ?>
                                <img src="/<?php echo h($currentAdmin['avatar']); ?>" class="admin-avatar-large" alt="头像">
                            <?php else: ?>
                                <div class="admin-avatar-large" style="background: var(--glass-bg); display: flex; align-items: center; justify-content: center; color: var(--accent-purple); font-size: 28px;">
                                    <?php echo mb_substr(h($currentAdmin['username']), 0, 1); ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <p style="color: var(--text-primary); margin-bottom: 5px;"><?php echo h($currentAdmin['username']); ?></p>
                                <p class="text-muted" style="font-size: 12px;">角色：<?php echo ($currentAdmin['role'] ?? 'sub') === 'super' ? '超级管理员' : '子管理员'; ?></p>
                                <p class="text-muted" style="font-size: 12px;">
                                    邮箱：<?php echo $currentAdmin['email'] ? h($currentAdmin['email']) : '未设置'; ?>
                                    <?php if ($currentAdmin['email']): ?>
                                        <?php if ($currentAdmin['email_verified']): ?>
                                            <span class="status status-approved" style="font-size:11px;margin-left:4px;">已验证</span>
                                        <?php else: ?>
                                            <span class="status status-unverified" style="font-size:11px;margin-left:4px;">未验证</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </p>
                                <p class="text-muted" style="font-size: 12px;">
                                    状态：<?php if ($currentAdmin['enabled']): ?>
                                        <span class="status status-enabled" style="font-size:11px;">已启用</span>
                                    <?php else: ?>
                                        <span class="status status-disabled" style="font-size:11px;">已禁用</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <form method="post" enctype="multipart/form-data" style="display: flex; gap: 10px; align-items: center;">
                            <input type="hidden" name="action" value="upload_avatar">
                            <input type="file" name="avatar" class="form-input" accept="image/jpeg,image/png,image/gif" style="max-width: 300px;" required>
                            <button type="submit" class="btn">上传头像</button>
                            <?php if (!empty($currentAdmin['avatar'])): ?>
                                <button type="submit" name="action" value="delete_avatar" class="btn btn-danger" onclick="return confirm('确定删除头像？')">删除头像</button>
                            <?php endif; ?>
                        </form>
                        <p class="form-hint">支持 JPG/PNG/GIF，最大 2MB</p>
                    </div>
                </div>

                <!-- 修改用户名 -->
                <div class="card settings-section">
                    <div class="card-header">修改用户名</div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="action" value="change_username">
                            <div class="form-group">
                                <label class="form-label">当前用户名</label>
                                <input type="text" class="form-input" value="<?php echo h($currentAdmin['username']); ?>" disabled style="max-width: 400px;">
                            </div>
                            <div class="form-group">
                                <label class="form-label">新用户名</label>
                                <input type="text" name="new_username" class="form-input" required minlength="2" maxlength="30" style="max-width: 400px;">
                                <p class="form-hint">2-30 个字符，已使用 <?php echo $currentAdmin['username_changes']; ?>/3 次修改机会</p>
                            </div>
                            <button type="submit" class="btn" <?php echo $currentAdmin['username_changes'] >= 3 ? 'disabled' : ''; ?>>修改用户名</button>
                        </form>
                    </div>
                </div>

                <!-- 修改密码 -->
                <div class="card settings-section">
                    <div class="card-header">修改密码</div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="action" value="change_password">
                            <div class="form-group">
                                <label class="form-label">旧密码</label>
                                <input type="password" name="old_password" class="form-input" required style="max-width: 400px;">
                            </div>
                            <div class="form-group">
                                <label class="form-label">新密码</label>
                                <input type="password" name="new_password" class="form-input" required minlength="8" style="max-width: 400px;">
                                <p class="form-hint">至少 8 位字符</p>
                            </div>
                            <div class="form-group">
                                <label class="form-label">确认新密码</label>
                                <input type="password" name="confirm_password" class="form-input" required minlength="8" style="max-width: 400px;">
                            </div>
                            <button type="submit" class="btn">修改密码</button>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <?php renderAdminFooterScripts(); ?>
</body>
</html>

