<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/mailer.php';

checkLogin();
requireSuperAdmin();

$pdo = getDB();
$message = '';
$messageType = '';

$action = $_GET['action'] ?? '';

// ===== AJAX 端点：管理员邮箱手动验证 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'verify_admin_email') {
    header('Content-Type: application/json; charset=utf-8');

    // 服务端限流：10秒内仅可操作1次
    $lastVerify = $_SESSION['last_admin_verify_time'] ?? 0;
    if (time() - $lastVerify < 10) {
        echo json_encode(['success' => false, 'message' => '操作过于频繁，请稍后再试']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $adminId = intval($input['admin_id'] ?? 0);

    if ($adminId <= 0) {
        echo json_encode(['success' => false, 'message' => '无效的管理员ID']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, username, email, role, email_verified FROM users WHERE id = ?");
    $stmt->execute([$adminId]);
    $target = $stmt->fetch();

    if (!$target) {
        echo json_encode(['success' => false, 'message' => '用户不存在']);
        exit;
    }
    if (!in_array($target['role'], ['sub', 'super'])) {
        echo json_encode(['success' => false, 'message' => '该用户不是管理员']);
        exit;
    }
    if (empty($target['email'])) {
        echo json_encode(['success' => false, 'message' => '该管理员未设置邮箱']);
        exit;
    }
    if ($target['email_verified']) {
        echo json_encode(['success' => false, 'message' => '该管理员邮箱已经验证过了']);
        exit;
    }

    $upStmt = $pdo->prepare("UPDATE users SET email_verified = 1, verify_token = NULL, verify_token_expires = NULL WHERE id = ? AND email_verified = 0");
    $upStmt->execute([$adminId]);

    if ($upStmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => '操作未生效，可能已被其他管理员验证']);
        exit;
    }

    // 发送通知邮件（失败不阻断）
    $mailSent = false;
    if (!empty($target['email'])) {
        $mailSent = sendAccountVerifiedEmail($target['email'], $target['username']);
    }

    // 写入操作日志
    $logAdminId = $_SESSION['admin_id'] ?? 0;
    $logAdminUsername = $_SESSION['admin_username'] ?? 'unknown';
    $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, admin_username, action, target_user_id, target_username, detail, result, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $logStmt->execute([
        $logAdminId,
        $logAdminUsername,
        'manual_verify_admin_email',
        $target['id'],
        $target['username'],
        json_encode(['email' => maskEmail($target['email']), 'mail_sent' => $mailSent], JSON_UNESCAPED_UNICODE),
        'success',
        getClientIP()
    ]);

    $_SESSION['last_admin_verify_time'] = time();
    echo json_encode(['success' => true, 'message' => '验证成功，管理员邮箱已激活']);
    exit;
}

// 权限模块定义
$permModules = [
    'games'          => ['label' => '游戏管理',     'actions' => ['view', 'add', 'edit', 'delete']],
    'game_review'    => ['label' => '游戏审核',     'actions' => ['view', 'edit', 'delete']],
    'categories'     => ['label' => '报错分类管理', 'actions' => ['view', 'add', 'edit', 'delete']],
    'errors'         => ['label' => '报错管理',     'actions' => ['view', 'edit', 'delete']],
    'articles'       => ['label' => '文章管理',     'actions' => ['view', 'edit', 'delete']],
    'users'          => ['label' => '用户管理',     'actions' => ['view', 'edit']],
    'site'           => ['label' => '站点外观',     'actions' => ['view', 'edit']],
    'sensitive_logs' => ['label' => '敏感词日志查看', 'actions' => ['view', 'add', 'edit', 'delete']],
    'url_whitelist'  => ['label' => 'URL 白名单管理', 'actions' => ['view', 'edit']],
    'documents'      => ['label' => '文档管理',     'actions' => ['view', 'add', 'edit', 'delete']],
    'todos'          => ['label' => '网站待办',     'actions' => ['view', 'add', 'edit', 'delete']],
];
$actionLabels = ['view' => '查看', 'add' => '添加', 'edit' => '编辑', 'delete' => '删除'];

// 添加子管理员
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $perms = $_POST['perm'] ?? [];

    if (empty($username) || mb_strlen($username) < 2 || mb_strlen($username) > 30) {
        $message = '用户名长度需在 2-30 个字符之间';
        $messageType = 'error';
    } elseif (mb_strlen($password) < 8) {
        $message = '密码长度不能少于 8 位';
        $messageType = 'error';
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()['count'] > 0) {
            $message = '用户名已存在';
            $messageType = 'error';
        } else {
            $permJson = json_encode($perms, JSON_UNESCAPED_UNICODE);
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO users (username, password, role, permissions) VALUES (?, ?, 'sub', ?)")
                ->execute([$username, $hash, $permJson]);

            // 发送管理员须知站内信
            $newAdminId = $pdo->lastInsertId();
            sendNotification($newAdminId, '恭喜成为管理员！请阅读管理员须知', '您已被设为管理员，请务必阅读管理员须知，了解管理职责和操作规范。', '/page/admin-guide');

            $message = '子管理员创建成功';
            $messageType = 'success';
            $action = '';
        }
    }
}

// 编辑子管理员
if ($action === 'edit' && isset($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_GET['id']);
    $perms = $_POST['perm'] ?? [];
    $enabled = isset($_POST['enabled']) ? 1 : 0;

    // 不能编辑超管
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target || $target['role'] === 'super') {
        $message = '无法编辑超级管理员';
        $messageType = 'error';
    } else {
        $permJson = json_encode($perms, JSON_UNESCAPED_UNICODE);
        $pdo->prepare("UPDATE users SET permissions = ?, enabled = ? WHERE id = ?")
            ->execute([$permJson, $enabled, $id]);
        $message = '管理员信息更新成功';
        $messageType = 'success';
        $action = '';
    }
}

// 删除子管理员
if ($action === 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    if ($id == $_SESSION['admin_id']) {
        $message = '不能删除自己的账户';
        $messageType = 'error';
    } else {
        $stmt = $pdo->prepare("SELECT role, avatar FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if (!$target) {
            $message = '用户不存在';
            $messageType = 'error';
        } elseif ($target['role'] === 'super') {
            $message = '不能删除超级管理员';
            $messageType = 'error';
        } else {
            if ($target['avatar'] && file_exists(BASE_PATH . $target['avatar'])) {
                unlink(BASE_PATH . $target['avatar']);
            }
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
            $message = '管理员已删除';
            $messageType = 'success';
        }
    }
    $action = '';
}

// 切换启用/禁用
if ($action === 'toggle' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("SELECT role, enabled FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) {
        $message = '用户不存在';
        $messageType = 'error';
    } elseif ($target['role'] === 'super') {
        $message = '不能禁用超级管理员';
        $messageType = 'error';
    } else {
        $newStatus = $target['enabled'] ? 0 : 1;
        $pdo->prepare("UPDATE users SET enabled = ? WHERE id = ?")->execute([$newStatus, $id]);
        $message = $newStatus ? '管理员已启用' : '管理员已禁用';
        $messageType = 'success';
    }
    $action = '';
}

// 重置密码
if ($action === 'reset_password' && isset($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_GET['id']);
    $newPassword = $_POST['new_password'] ?? '';
    if (mb_strlen($newPassword) < 8) {
        $message = '密码长度不能少于 8 位';
        $messageType = 'error';
    } else {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if (!$target || $target['role'] === 'super') {
            $message = '无法重置超级管理员密码';
            $messageType = 'error';
        } else {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $id]);
            $message = '密码已重置';
            $messageType = 'success';
        }
    }
    $action = '';
}

// 获取管理员列表
$admins = $pdo->query("SELECT id, username, role, avatar, permissions, enabled, username_changes, email, email_verified, created_at FROM users WHERE role IN ('sub','super') ORDER BY id ASC")->fetchAll();

// 获取编辑的管理员
$editAdmin = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $editAdmin = $stmt->fetch();
    if ($editAdmin) {
        $editAdmin['_perms'] = json_decode($editAdmin['permissions'] ?? '{}', true) ?: [];
    }
}

// 重置密码目标
$resetAdmin = null;
if ($action === 'reset_password' && isset($_GET['id']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $resetAdmin = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员设置 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <?php renderAdminHeadScript(); ?>
</head>
<body class="has-fixed-nav">
    <?php include '../includes/header.php'; ?>
    <div class="admin-layout">
        <?php renderAdminSidebar('users.php'); ?>

        <div class="admin-content">
            <main class="admin-main">
                <div class="admin-page-header">
                    <h1>管理员设置</h1>
                    <a href="?action=add" class="btn">添加子管理员</a>
                </div>
                <div id="messageArea">
                <?php if ($message): ?>
                    <div class="admin-alert-<?php echo $messageType; ?>">
                        <?php echo h($message); ?>
                    </div>
                <?php endif; ?>
                </div>

                <?php if ($action === 'add'): ?>
                    <!-- 添加子管理员 -->
                    <div class="card">
                        <div class="card-header">添加子管理员</div>
                        <div class="card-body">
                            <form method="post">
                                <div class="form-group">
                                    <label class="form-label">用户名 *</label>
                                    <input type="text" name="username" class="form-input" required minlength="2" maxlength="30" style="max-width: 400px;">
                                    <p class="form-hint">2-30 个字符</p>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">密码 *</label>
                                    <input type="password" name="password" class="form-input" required minlength="8" style="max-width: 400px;">
                                    <p class="form-hint">至少 8 位</p>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">权限矩阵</label>
                                    <table class="permission-matrix">
                                        <thead>
                                            <tr>
                                                <th>模块</th>
                                                <?php foreach ($actionLabels as $act => $label): ?>
                                                    <th><?php echo $label; ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($permModules as $mod => $info): ?>
                                                <tr>
                                                    <td><?php echo h($info['label']); ?></td>
                                                    <?php foreach ($actionLabels as $act => $label): ?>
                                                        <td>
                                                            <?php if (in_array($act, $info['actions'])): ?>
                                                                <input type="checkbox" name="perm[<?php echo $mod; ?>][]" value="<?php echo $act; ?>">
                                                            <?php else: ?>
                                                                -
                                                            <?php endif; ?>
                                                        </td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="btn-group">
                                    <button type="submit" class="btn">创建管理员</button>
                                    <a href="users.php" class="btn btn-secondary">取消</a>
                                </div>
                            </form>
                        </div>
                    </div>

                <?php elseif ($action === 'edit' && $editAdmin): ?>
                    <!-- 编辑子管理员 -->
                    <div class="card">
                        <div class="card-header">编辑管理员：<?php echo h($editAdmin['username']); ?></div>
                        <div class="card-body">
                            <form method="post">
                                <div class="form-group">
                                    <label class="form-label">启用状态</label>
                                    <label style="font-weight: normal; cursor: pointer;">
                                        <input type="checkbox" name="enabled" value="1" <?php echo $editAdmin['enabled'] ? 'checked' : ''; ?>> 启用此管理员
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">权限矩阵</label>
                                    <table class="permission-matrix">
                                        <thead>
                                            <tr>
                                                <th>模块</th>
                                                <?php foreach ($actionLabels as $act => $label): ?>
                                                    <th><?php echo $label; ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($permModules as $mod => $info): ?>
                                                <tr>
                                                    <td><?php echo h($info['label']); ?></td>
                                                    <?php foreach ($actionLabels as $act => $label): ?>
                                                        <td>
                                                            <?php if (in_array($act, $info['actions'])): ?>
                                                                <input type="checkbox" name="perm[<?php echo $mod; ?>][]" value="<?php echo $act; ?>"
                                                                    <?php echo (isset($editAdmin['_perms'][$mod]) && in_array($act, $editAdmin['_perms'][$mod])) ? 'checked' : ''; ?>>
                                                            <?php else: ?>
                                                                -
                                                            <?php endif; ?>
                                                        </td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="btn-group">
                                    <button type="submit" class="btn">保存修改</button>
                                    <a href="users.php" class="btn btn-secondary">取消</a>
                                </div>
                            </form>
                        </div>
                    </div>

                <?php elseif ($action === 'reset_password' && $resetAdmin): ?>
                    <!-- 重置密码 -->
                    <div class="card">
                        <div class="card-header">重置密码：<?php echo h($resetAdmin['username']); ?></div>
                        <div class="card-body">
                            <form method="post">
                                <div class="form-group">
                                    <label class="form-label">新密码 *</label>
                                    <input type="password" name="new_password" class="form-input" required minlength="8" style="max-width: 400px;">
                                    <p class="form-hint">至少 8 位</p>
                                </div>
                                <div class="btn-group">
                                    <button type="submit" class="btn">重置密码</button>
                                    <a href="users.php" class="btn btn-secondary">取消</a>
                                </div>
                            </form>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- 管理员列表 -->
                    <div class="card">
                        <div class="card-header">
                            管理员列表
                            <span class="text-muted" style="float: right; font-weight: normal;">共 <?php echo count($admins); ?> 个管理员</span>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>用户名</th>
                                            <th>角色</th>
                                            <th>邮箱</th>
                                            <th>状态</th>
                                            <th>创建时间</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($admins as $admin): ?>
                                            <tr>
                                                <td><?php echo $admin['id']; ?></td>
                                                <td>
                                                    <div style="display: flex; align-items: center; gap: 8px;">
                                                        <?php if ($admin['avatar'] && file_exists(BASE_PATH . $admin['avatar'])): ?>
                                                            <img src="/<?php echo h($admin['avatar']); ?>" class="admin-avatar-small" alt="">
                                                        <?php endif; ?>
                                                        <a href="/profile?user_id=<?php echo $admin['id']; ?>" style="color: var(--text-primary); text-decoration: none;">
                                                            <strong><?php echo h($admin['username']); ?></strong>
                                                        </a>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php echo $admin['role'] === 'super' ? '<span style="color: var(--accent-purple);">超级管理员</span>' : '子管理员'; ?>
                                                </td>
                                                <td style="font-size: 0.85rem;">
                                                    <?php
                                                        if (isSuperAdmin()) {
                                                            echo $admin['email'] ? h($admin['email']) : '<span class="text-muted">未设置</span>';
                                                        } else {
                                                            echo h(maskEmail($admin['email']));
                                                        }
                                                    ?>
                                                    <?php if ($admin['email']): ?>
                                                        <?php if ($admin['email_verified']): ?>
                                                            <span class="status status-approved" style="font-size:11px;margin-left:4px;">已验证</span>
                                                        <?php else: ?>
                                                            <span class="status status-unverified" style="font-size:11px;margin-left:4px;">未验证</span>
                                                            <button type="button" class="btn btn-verify btn-sm btn-verify-admin" style="font-size:11px;padding:2px 8px;margin-left:4px;" data-id="<?php echo $admin['id']; ?>" data-username="<?php echo h($admin['username']); ?>" data-email="<?php echo h($admin['email']); ?>">验证</button>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="status-<?php echo $admin['enabled'] ? 'enabled' : 'disabled'; ?>">
                                                        <?php echo $admin['enabled'] ? '启用' : '禁用'; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('Y-m-d H:i', strtotime($admin['created_at'])); ?></td>
                                                <td>
                                                    <div class="action-btns">
                                                        <?php if ($admin['role'] !== 'super'): ?>
                                                            <a href="?action=edit&id=<?php echo $admin['id']; ?>" class="btn btn-sm">编辑</a>
                                                            <a href="?action=toggle&id=<?php echo $admin['id']; ?>" class="btn btn-sm btn-secondary">
                                                                <?php echo $admin['enabled'] ? '禁用' : '启用'; ?>
                                                            </a>
                                                            <a href="?action=reset_password&id=<?php echo $admin['id']; ?>" class="btn btn-sm btn-secondary">重置密码</a>
                                                            <a href="?action=delete&id=<?php echo $admin['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('确定删除此管理员？')">删除</a>
                                                        <?php else: ?>
                                                            <span class="text-muted" style="font-size: 12px;">超管不可操作</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- 管理员邮箱验证确认弹窗 -->
    <div class="verify-modal-overlay" id="adminVerifyModal">
        <div class="verify-modal">
            <div class="verify-modal-header">
                <span class="verify-modal-title">确认手动验证</span>
                <button class="verify-modal-close" id="adminModalClose">&times;</button>
            </div>
            <div class="verify-modal-body" id="adminModalBody"></div>
            <div class="verify-modal-footer">
                <button class="btn btn-secondary btn-sm" id="adminModalCancel">取消</button>
                <button class="btn btn-verify btn-sm" id="adminModalConfirm">确认验证</button>
            </div>
        </div>
    </div>

    <script>
    (function() {
        'use strict';

        var DEBOUNCE_MS = 10000;
        var lastVerifyTime = 0;
        var pendingAdminId = null;

        var overlay = document.getElementById('adminVerifyModal');
        var modalBody = document.getElementById('adminModalBody');
        var modalConfirm = document.getElementById('adminModalConfirm');
        var modalCancel = document.getElementById('adminModalCancel');
        var modalClose = document.getElementById('adminModalClose');
        var messageArea = document.getElementById('messageArea');

        function checkDebounce() {
            var elapsed = Date.now() - lastVerifyTime;
            if (elapsed < DEBOUNCE_MS) {
                var wait = Math.ceil((DEBOUNCE_MS - elapsed) / 1000);
                showMessage('操作过于频繁，请等待 ' + wait + ' 秒', 'error');
                return false;
            }
            return true;
        }

        function showMessage(text, type) {
            var div = document.createElement('div');
            div.className = 'admin-alert-' + type;
            div.textContent = text;
            messageArea.innerHTML = '';
            messageArea.appendChild(div);
            setTimeout(function() {
                if (div.parentNode) div.parentNode.removeChild(div);
            }, 3000);
        }

        function escapeHtml(str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }

        function openModal(bodyHtml, adminId) {
            modalBody.innerHTML = bodyHtml;
            pendingAdminId = adminId;
            modalConfirm.disabled = false;
            modalConfirm.textContent = '确认验证';
            overlay.classList.add('active');
        }

        function closeModal() {
            overlay.classList.remove('active');
            pendingAdminId = null;
        }

        modalClose.addEventListener('click', closeModal);
        modalCancel.addEventListener('click', closeModal);
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeModal();
        });

        // 验证按钮点击（事件委托）
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.btn-verify-admin');
            if (!btn) return;
            e.preventDefault();

            if (!checkDebounce()) return;

            var id = btn.getAttribute('data-id');
            var username = btn.getAttribute('data-username');
            var email = btn.getAttribute('data-email');

            var html = '<p>确认给管理员<strong>【' + escapeHtml(username) + '】</strong>的邮箱<strong>【' + escapeHtml(email) + '】</strong>完成验证？</p>'
                + '<ul><li>将管理员邮箱标记为已验证</li><li>向管理员发送验证成功通知邮件</li></ul>';

            openModal(html, id);
        });

        // 确认验证
        modalConfirm.addEventListener('click', function() {
            if (!pendingAdminId || modalConfirm.disabled) return;

            modalConfirm.disabled = true;
            modalConfirm.textContent = '处理中...';

            fetch('?action=verify_admin_email', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({admin_id: parseInt(pendingAdminId)})
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                closeModal();
                lastVerifyTime = Date.now();
                if (data.success) {
                    showMessage(data.message, 'success');
                    setTimeout(function() { location.reload(); }, 1000);
                } else {
                    showMessage(data.message, 'error');
                }
            })
            .catch(function(err) {
                closeModal();
                showMessage('请求失败，请重试', 'error');
            });
        });
    })();
    </script>

    <?php renderAdminFooterScripts(); ?>
</body>
</html>

