<?php
require_once 'includes/user_auth.php';
require_once 'includes/image_utils.php';
require_once 'includes/sanitizer.php';

requireUserLogin();

$pdo = getDB();
$currentUserId = getCurrentUserId();
$isAdminUser = canCurrentUserBypassModeration();

// 支持通过 ?user_id=X 查看其他用户
$viewUserId = intval($_GET['user_id'] ?? 0);
if ($viewUserId <= 0 || $viewUserId === $currentUserId) {
    $viewUserId = $currentUserId;
    $isOwner = true;
} else {
    $isOwner = false;
}
$userId = $viewUserId;

$message = '';
$messageType = '';

$tab = $_GET['tab'] ?? 'info';
$getAction = $_GET['action'] ?? '';

// 非本人时禁止编辑操作
if (!$isOwner && $getAction === 'edit') {
    $getAction = '';
}

// AJAX: 头像裁剪图片上传
if (($_GET['action'] ?? '') === 'upload_cropped_avatar' && $_SERVER['REQUEST_METHOD'] === 'POST' && $isOwner) {
    $result = handleCroppedAvatarData($_POST['image_data'] ?? '');
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// 处理 POST 操作（仅本人可操作）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOwner) {
    if (isUserBanned()) {
        $message = '您的账户已被封禁，无法修改个人信息';
        $messageType = 'error';
    } else {
    $postAction = $_POST['action'] ?? '';

    // 上传头像
    if ($postAction === 'upload_avatar') {
        $croppedAvatarPath = trim($_POST['cropped_avatar_path'] ?? '');
        if (!empty($croppedAvatarPath) && strpos($croppedAvatarPath, UPLOAD_PATH . 'avatars/') === 0
            && !preg_match('/\.\./', $croppedAvatarPath) && file_exists(BASE_PATH . $croppedAvatarPath)) {
            $result = ['success' => true, 'path' => $croppedAvatarPath];
        } elseif (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $result = handleAvatarUpload($_FILES['avatar']);
        } else {
            $result = ['success' => false, 'message' => '请先选择并裁剪头像'];
        }

        if ($result['success']) {
            // 删除旧头像
            $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $old = $stmt->fetch();
            if ($old && $old['avatar'] && file_exists(BASE_PATH . $old['avatar'])) {
                unlink(BASE_PATH . $old['avatar']);
            }
            $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?")->execute([$result['path'], $userId]);
            $_SESSION['user_avatar'] = $result['path'];
            if (isAdmin()) {
                $_SESSION['admin_avatar'] = $result['path'];
            }
            $message = '头像上传成功';
            $messageType = 'success';
        } else {
            $message = $result['message'];
            $messageType = 'error';
        }
    }

    // 删除头像
    if ($postAction === 'delete_avatar') {
        $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $old = $stmt->fetch();
        if ($old && $old['avatar'] && file_exists(BASE_PATH . $old['avatar'])) {
            unlink(BASE_PATH . $old['avatar']);
        }
        $pdo->prepare("UPDATE users SET avatar = NULL WHERE id = ?")->execute([$userId]);
        $_SESSION['user_avatar'] = '';
        if (isAdmin()) {
            $_SESSION['admin_avatar'] = '';
        }
        $message = '头像已删除';
        $messageType = 'success';
    }

    // 修改用户名
    if ($postAction === 'change_username') {
        $newUsername = trim($_POST['new_username'] ?? '');
        if (empty($newUsername) || mb_strlen($newUsername) < 2 || mb_strlen($newUsername) > 30) {
            $message = '用户名长度需在 2-30 个字符之间';
            $messageType = 'error';
        } elseif (!preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z0-9_]+$/u', $newUsername)) {
            $message = '用户名只能包含中文、英文、数字和下划线';
            $messageType = 'error';
        } else {
            $stmt = $pdo->prepare("SELECT username_changes FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            $usedChanges = $row['username_changes'] ?? 0;
            if ($usedChanges >= 3) {
                $message = '您已达到最大修改次数（3次），无法继续修改用户名';
                $messageType = 'error';
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM users WHERE username = ? AND id != ?");
                $stmt->execute([$newUsername, $userId]);
                if ($stmt->fetch()['cnt'] > 0) {
                    $message = '该用户名已被使用';
                    $messageType = 'error';
                } else {
                    $pdo->prepare("UPDATE users SET username = ?, username_changes = username_changes + 1 WHERE id = ?")
                        ->execute([$newUsername, $userId]);
                    $_SESSION['user_username'] = $newUsername;
                    if (isAdmin()) {
                        $_SESSION['admin_username'] = $newUsername;
                    }
                    $message = '用户名修改成功（已使用 ' . ($usedChanges + 1) . '/3 次修改机会）';
                    $messageType = 'success';
                }
            }
        }
    }

    // 修改密码
    if ($postAction === 'change_password') {
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
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (!$row || !password_verify($oldPassword, $row['password'])) {
                $message = '旧密码错误';
                $messageType = 'error';
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $userId]);
                $message = '密码修改成功';
                $messageType = 'success';
            }
        }
    }

    // 编辑游戏
    if ($postAction === 'edit_game') {
        $gameId = intval($_POST['game_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $titleJp = trim($_POST['title_jp'] ?? '');
        $romaji = trim($_POST['romaji'] ?? '');
        $aliases = trim($_POST['aliases'] ?? '');
        $developer = trim($_POST['developer'] ?? '');
        $releaseDate = trim($_POST['release_date'] ?? '');
        $platforms = trim($_POST['platforms'] ?? '');

        if (empty($title)) {
            $message = '游戏标题不能为空';
            $messageType = 'error';
        } else {
            // 安全：只能编辑自己提交的
            $stmt = $pdo->prepare("SELECT id FROM games WHERE id = ? AND user_id = ?");
            $stmt->execute([$gameId, $userId]);
            if (!$stmt->fetch()) {
                $message = '无权编辑该游戏';
                $messageType = 'error';
            } else {
                $updateStatus = getCurrentUserModerationStatus();
                $pdo->prepare("UPDATE games SET title = ?, title_jp = ?, romaji = ?, aliases = ?, developer = ?, release_date = ?, platforms = ?, status = ? WHERE id = ? AND user_id = ?")
                    ->execute([$title, $titleJp, $romaji, $aliases ?: null, $developer, $releaseDate ?: null, $platforms, $updateStatus, $gameId, $userId]);
                $message = $isAdminUser ? '游戏信息已更新并直接生效' : '游戏信息已更新，将重新进入审核';
                $messageType = 'success';
                // 重定向回游戏列表避免重复提交
                header('Location: /profile?tab=games&msg=' . urlencode($message) . '&msgtype=success');
                exit;
            }
        }
        $tab = 'games';
        $getAction = 'edit';
    }

    // 编辑报错
    if ($postAction === 'edit_error') {
        $errorId = intval($_POST['error_id'] ?? 0);
        $categoryId = intval($_POST['category_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $phenomenon = trim($_POST['phenomenon'] ?? '');
        $systemInfo = trim($_POST['system_info'] ?? '');
        $patchInfo = trim($_POST['patch_info'] ?? '');
        $solution = trim($_POST['solution'] ?? '');

        if (empty($title) || !$categoryId) {
            $message = '请填写报错标题和选择分类';
            $messageType = 'error';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM errors WHERE id = ? AND user_id = ?");
            $stmt->execute([$errorId, $userId]);
            if (!$stmt->fetch()) {
                $message = '无权编辑该报错';
                $messageType = 'error';
            } else {
                $updateStatus = getCurrentUserModerationStatus();
                $pdo->prepare("UPDATE errors SET category_id = ?, title = ?, phenomenon = ?, system_info = ?, patch_info = ?, solution = ?, status = ? WHERE id = ? AND user_id = ?")
                    ->execute([$categoryId, $title, $phenomenon, $systemInfo, $patchInfo, $solution, $updateStatus, $errorId, $userId]);
                $message = $isAdminUser ? '报错信息已更新并直接生效' : '报错信息已更新，将重新进入审核';
                $messageType = 'success';
                header('Location: /profile?tab=errors&msg=' . urlencode($message) . '&msgtype=success');
                exit;
            }
        }
        $tab = 'errors';
        $getAction = 'edit';
    }
    }
}

// 接收重定向消息
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $allowedMsgTypes = ['success', 'error', 'warning', 'info'];
    $rawType = $_GET['msgtype'] ?? 'success';
    $messageType = in_array($rawType, $allowedMsgTypes, true) ? $rawType : 'success';
}

// 获取目标用户信息
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$currentUser = $stmt->fetch();

if (!$currentUser) {
    if ($isOwner) {
        clearUserSession();
        header('Location: /login');
        exit;
    } else {
        header('Location: /');
        exit;
    }
}

// URL 辅助变量
$userIdParam = $isOwner ? '' : '&user_id=' . $viewUserId;

// Tab 数据
$tabData = [];

if ($tab === 'stats') {
    $approvedFilter = $isOwner ? '' : " AND status = 'approved'";

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM games WHERE user_id = ?" . $approvedFilter);
    $stmt->execute([$userId]);
    $tabData['games_count'] = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM errors WHERE user_id = ?" . $approvedFilter);
    $stmt->execute([$userId]);
    $tabData['errors_count'] = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM errors WHERE user_id = ? AND solution IS NOT NULL AND solution != ''" . $approvedFilter);
    $stmt->execute([$userId]);
    $tabData['solutions_count'] = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM articles WHERE user_id = ?" . $approvedFilter);
    $stmt->execute([$userId]);
    $tabData['articles_count'] = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM discussions WHERE user_id = ? AND status = 'active'");
    $stmt->execute([$userId]);
    $tabData['discussions_count'] = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE user_id = ? AND status = 'active' AND (parent_id IS NULL)");
    $stmt->execute([$userId]);
    $tabData['comments_count'] = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE user_id = ? AND status = 'active' AND parent_id IS NOT NULL");
    $stmt->execute([$userId]);
    $tabData['replies_count'] = $stmt->fetchColumn();
}

if ($tab === 'games') {
    $perPage = 15;
    $page = max(1, intval($_GET['page'] ?? 1));

    if ($getAction === 'edit' && isset($_GET['id'])) {
        $editId = intval($_GET['id']);
        $stmt = $pdo->prepare("SELECT * FROM games WHERE id = ? AND user_id = ?");
        $stmt->execute([$editId, $userId]);
        $tabData['edit_game'] = $stmt->fetch();
    } else {
        $statusFilter = $isOwner ? '' : " AND status = 'approved'";
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM games WHERE user_id = ?" . $statusFilter);
        $stmt->execute([$userId]);
        $total = $stmt->fetchColumn();
        $pagination = paginate($total, $page, $perPage);

        $stmt = $pdo->prepare("SELECT * FROM games WHERE user_id = ?" . $statusFilter . " ORDER BY created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$userId, $pagination['perPage'], $pagination['offset']]);
        $tabData['games'] = $stmt->fetchAll();
        $tabData['pagination'] = $pagination;
    }
}

if ($tab === 'errors') {
    $perPage = 15;
    $page = max(1, intval($_GET['page'] ?? 1));

    if ($getAction === 'edit' && isset($_GET['id'])) {
        $editId = intval($_GET['id']);
        $stmt = $pdo->prepare("SELECT e.*, g.title as game_title FROM errors e LEFT JOIN games g ON e.game_id = g.id WHERE e.id = ? AND e.user_id = ?");
        $stmt->execute([$editId, $userId]);
        $tabData['edit_error'] = $stmt->fetch();

        // 获取报错分类
        $tabData['categories'] = $pdo->query("SELECT * FROM error_categories ORDER BY sort_order ASC")->fetchAll();
    } else {
        $statusFilter = $isOwner ? '' : " AND e.status = 'approved'";
        $countFilter = $isOwner ? '' : " AND status = 'approved'";
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM errors WHERE user_id = ?" . $countFilter);
        $stmt->execute([$userId]);
        $total = $stmt->fetchColumn();
        $pagination = paginate($total, $page, $perPage);

        $stmt = $pdo->prepare("SELECT e.*, g.title as game_title FROM errors e LEFT JOIN games g ON e.game_id = g.id WHERE e.user_id = ?" . $statusFilter . " ORDER BY e.created_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$userId, $pagination['perPage'], $pagination['offset']]);
        $tabData['errors'] = $stmt->fetchAll();
        $tabData['pagination'] = $pagination;
    }
}

if ($tab === 'articles') {
    $perPage = 15;
    $page = max(1, intval($_GET['page'] ?? 1));

    $statusFilter = $isOwner ? '' : " AND status = 'approved'";
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM articles WHERE user_id = ?" . $statusFilter);
    $stmt->execute([$userId]);
    $total = $stmt->fetchColumn();
    $pagination = paginate($total, $page, $perPage);

    $stmt = $pdo->prepare("SELECT * FROM articles WHERE user_id = ?" . $statusFilter . " ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([$userId, $pagination['perPage'], $pagination['offset']]);
    $tabData['articles'] = $stmt->fetchAll();
    $tabData['pagination'] = $pagination;
}

if ($tab === 'discussions') {
    $perPage = 15;
    $page = max(1, intval($_GET['page'] ?? 1));

    $statusFilter = $isOwner ? " AND status != 'deleted'" : " AND status = 'active'";
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM discussions WHERE user_id = ?" . $statusFilter);
    $stmt->execute([$userId]);
    $total = $stmt->fetchColumn();
    $pagination = paginate($total, $page, $perPage);

    $stmt = $pdo->prepare("SELECT * FROM discussions WHERE user_id = ?" . $statusFilter . " ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([$userId, $pagination['perPage'], $pagination['offset']]);
    $tabData['discussions'] = $stmt->fetchAll();
    $tabData['pagination'] = $pagination;
}

// 状态标签
function statusLabel($status) {
    $map = [
        'pending' => ['待审核', 'pending'],
        'approved' => ['已通过', 'approved'],
        'rejected' => ['已拒绝', 'rejected'],
        'active' => ['正常', 'approved'],
        'deleted' => ['已删除', 'rejected'],
    ];
    $info = $map[$status] ?? ['未知', ''];
    return '<span class="status-badge ' . $info[1] . '">' . $info[0] . '</span>';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isOwner ? '个人主页' : h($currentUser['username']) . ' 的主页'; ?> - <?php echo h(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/user.css?v=<?php echo ASSETS_VER; ?>">
    <?php if ($isOwner && $tab === 'info'): ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
    <?php endif; ?>
    <?php renderSiteHead(); ?>
</head>
<body class="has-fixed-nav">
    <?php include 'includes/header.php'; ?>

    <main class="main">
        <div class="container">
            <?php if ($message): ?>
                <div class="alert-<?php echo $messageType; ?>" style="margin-bottom: 20px;">
                    <?php echo h($message); ?>
                </div>
            <?php endif; ?>

            <div class="profile-layout">
                <!-- 侧边栏 -->
                <div class="profile-sidebar">
                    <div class="profile-sidebar-card">
                        <div class="profile-avatar-wrapper">
                            <?php if (!empty($currentUser['avatar']) && file_exists(BASE_PATH . $currentUser['avatar'])): ?>
                                <img src="/<?php echo h($currentUser['avatar']); ?>" class="profile-avatar-large" alt="头像">
                            <?php else: ?>
                                <div class="profile-avatar-large fallback"><?php echo h(mb_substr($currentUser['username'], 0, 1)); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="profile-username"><?php echo h($currentUser['username']); ?></div>
                        <div class="profile-role">
                            <span class="user-role-badge role-<?php echo h($currentUser['role']); ?>"><?php echo h(getRoleLabel($currentUser['role'])); ?></span>
                        </div>
                        <?php if (!$isOwner && isUserLoggedIn()): ?>
                            <a href="<?php echo urlChat($viewUserId); ?>" class="btn btn-sm" style="width:100%;margin-top:12px;margin-bottom:4px;text-align:center;">发私信</a>
                        <?php endif; ?>
                        <ul class="profile-menu">
                            <li><a href="/profile?tab=info<?php echo $userIdParam; ?>" class="<?php echo $tab === 'info' ? 'active' : ''; ?>">个人信息</a></li>
                            <li><a href="/profile?tab=stats<?php echo $userIdParam; ?>" class="<?php echo $tab === 'stats' ? 'active' : ''; ?>">数据统计</a></li>
                            <li><a href="/profile?tab=games<?php echo $userIdParam; ?>" class="<?php echo $tab === 'games' ? 'active' : ''; ?>"><?php echo $isOwner ? '游戏管理' : '游戏'; ?></a></li>
                            <li><a href="/profile?tab=errors<?php echo $userIdParam; ?>" class="<?php echo $tab === 'errors' ? 'active' : ''; ?>"><?php echo $isOwner ? '报错管理' : '报错'; ?></a></li>
                            <li><a href="/profile?tab=articles<?php echo $userIdParam; ?>" class="<?php echo $tab === 'articles' ? 'active' : ''; ?>"><?php echo $isOwner ? '文章管理' : '文章'; ?></a></li>
                            <li><a href="/profile?tab=discussions<?php echo $userIdParam; ?>" class="<?php echo $tab === 'discussions' ? 'active' : ''; ?>"><?php echo $isOwner ? '话题管理' : '话题'; ?></a></li>
                        </ul>
                    </div>
                </div>

                <!-- 内容区 -->
                <div class="profile-content">

                    <?php if ($tab === 'info'): ?>
                    <!-- Tab 1: 个人信息 -->
                    <div class="profile-card">
                        <h2>个人信息</h2>
                        <table class="info-table">
                            <?php if ($isOwner): ?>
                            <tr>
                                <td>用户 ID</td>
                                <td><?php echo $currentUser['id']; ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td>用户名</td>
                                <td><?php echo h($currentUser['username']); ?></td>
                            </tr>
                            <?php if ($isOwner): ?>
                            <tr>
                                <td>邮箱</td>
                                <td>
                                    <?php echo h(maskEmail($currentUser['email'])); ?>
                                    <?php if ($currentUser['email_verified']): ?>
                                        <span style="color: var(--accent-green); font-size: 0.8rem; margin-left: 6px;">已验证</span>
                                    <?php else: ?>
                                        <span style="color: #fbbf24; font-size: 0.8rem; margin-left: 6px;">未验证</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <td><?php echo $isOwner ? '角色' : '身份'; ?></td>
                                <td><span class="user-role-badge role-<?php echo h($currentUser['role']); ?>"><?php echo h(getRoleLabel($currentUser['role'])); ?></span></td>
                            </tr>
                            <tr>
                                <td>注册时间</td>
                                <td><?php echo date('Y-m-d H:i', strtotime($currentUser['created_at'])); ?></td>
                            </tr>
                        </table>
                    </div>

                    <?php if ($isOwner): ?>
                    <!-- 头像设置 -->
                    <div class="profile-card" style="margin-top: 20px;">
                        <h2>头像设置</h2>
                        <div style="display: flex; align-items: center; gap: 16px; margin-bottom: 16px;">
                            <?php if (!empty($currentUser['avatar']) && file_exists(BASE_PATH . $currentUser['avatar'])): ?>
                                <img src="/<?php echo h($currentUser['avatar']); ?>" style="width: 64px; height: 64px; border-radius: 50%; object-fit: cover; border: 2px solid var(--accent-purple);" alt="头像">
                            <?php else: ?>
                                <div style="width: 64px; height: 64px; border-radius: 50%; background: var(--gradient-primary); display: flex; align-items: center; justify-content: center; color: var(--bg-primary); font-size: 24px; font-weight: 700;">
                                    <?php echo h(mb_substr($currentUser['username'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <span style="color: var(--text-muted); font-size: 0.85rem;">支持 JPG/PNG，最大 2MB；默认圆形裁剪</span>
                        </div>
                        <form method="post" enctype="multipart/form-data" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;" id="avatarForm">
                            <input type="hidden" name="action" value="upload_avatar">
                            <input type="hidden" name="cropped_avatar_path" id="cropped_avatar_path" value="">
                            <input type="file" name="avatar" class="form-input" id="avatar_file" accept="image/jpeg,image/png" style="max-width: 280px;" required>
                            <button type="button" class="btn btn-secondary" id="avatarCropBtn" style="display:none;" onclick="openAvatarCropper()">裁剪头像</button>
                            <button type="submit" class="btn">上传头像</button>
                            <span id="avatar_crop_status" class="crop-status" style="display:none;"></span>
                            <?php if (!empty($currentUser['avatar'])): ?>
                                <button type="submit" name="action" value="delete_avatar" class="btn btn-danger" onclick="return confirm('确定删除头像？')">删除</button>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- 修改用户名 -->
                    <div class="profile-card" style="margin-top: 20px;">
                        <h2>修改用户名</h2>
                        <form method="post">
                            <input type="hidden" name="action" value="change_username">
                            <div class="form-group">
                                <label class="form-label">当前用户名</label>
                                <input type="text" class="form-input" value="<?php echo h($currentUser['username']); ?>" disabled style="max-width: 400px;">
                            </div>
                            <div class="form-group">
                                <label class="form-label">新用户名</label>
                                <input type="text" name="new_username" class="form-input" required minlength="2" maxlength="30" style="max-width: 400px;">
                                <p class="form-hint">2-30 个字符，支持中文/英文/数字/下划线。已使用 <?php echo $currentUser['username_changes']; ?>/3 次修改机会</p>
                            </div>
                            <button type="submit" class="btn" <?php echo $currentUser['username_changes'] >= 3 ? 'disabled' : ''; ?>>修改用户名</button>
                        </form>
                    </div>

                    <!-- 修改密码 -->
                    <div class="profile-card" style="margin-top: 20px;">
                        <h2>修改密码</h2>
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
                    <?php endif; ?>

                    <?php elseif ($tab === 'stats'): ?>
                    <!-- Tab 2: 数据统计 -->
                    <div class="profile-card">
                        <h2>数据统计</h2>
                        <div class="stat-grid">
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $tabData['games_count']; ?></div>
                                <div class="stat-label">提交游戏数</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $tabData['errors_count']; ?></div>
                                <div class="stat-label">提交报错数</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $tabData['solutions_count']; ?></div>
                                <div class="stat-label">提交方案数</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $tabData['articles_count'] ?? 0; ?></div>
                                <div class="stat-label">提交文章数</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $tabData['discussions_count'] ?? 0; ?></div>
                                <div class="stat-label">提交话题数</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $tabData['comments_count'] ?? 0; ?></div>
                                <div class="stat-label">评论数</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-number"><?php echo $tabData['replies_count'] ?? 0; ?></div>
                                <div class="stat-label">回复数</div>
                            </div>
                        </div>
                    </div>

                    <?php elseif ($tab === 'games'): ?>
                    <!-- Tab 3: 游戏管理 -->
                    <?php if ($getAction === 'edit' && !empty($tabData['edit_game'])): ?>
                        <?php $g = $tabData['edit_game']; ?>
                        <div class="profile-card">
                            <h2>编辑游戏</h2>
                            <form method="post">
                                <input type="hidden" name="action" value="edit_game">
                                <input type="hidden" name="game_id" value="<?php echo $g['id']; ?>">
                                <div class="form-group">
                                    <label class="form-label">VNDB ID</label>
                                    <input type="text" class="form-input" value="<?php echo h($g['vndb_id']); ?>" disabled style="max-width: 200px;">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">游戏标题 *</label>
                                    <input type="text" name="title" class="form-input" required value="<?php echo h($g['title']); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">日文原名</label>
                                    <input type="text" name="title_jp" class="form-input" value="<?php echo h($g['title_jp'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">罗马音</label>
                                    <input type="text" name="romaji" class="form-input" value="<?php echo h($g['romaji'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">别名</label>
                                    <input type="text" name="aliases" class="form-input" value="<?php echo h($g['aliases'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">开发商</label>
                                    <input type="text" name="developer" class="form-input" value="<?php echo h($g['developer'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">发售日</label>
                                    <input type="date" name="release_date" class="form-input" value="<?php echo h($g['release_date'] ?? ''); ?>" style="max-width: 200px;">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">平台</label>
                                    <input type="text" name="platforms" class="form-input" value="<?php echo h($g['platforms'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <p class="form-hint" style="color: #fbbf24;">提交后游戏状态将变为"待审核"，需管理员重新审核通过后才会显示。</p>
                                </div>
                                <div class="btn-group">
                                    <button type="submit" class="btn">保存修改</button>
                                    <a href="/profile?tab=games" class="btn btn-secondary">返回列表</a>
                                </div>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="profile-card">
                            <h2><?php echo $isOwner ? '我的游戏' : h($currentUser['username']) . ' 的游戏'; ?></h2>
                            <?php if (empty($tabData['games'])): ?>
                                <div class="empty-state">
                                    <p><?php echo $isOwner ? '您还没有提交过游戏' : '该用户还没有游戏'; ?></p>
                                    <?php if ($isOwner): ?>
                                    <p><a href="/submit_game" class="btn" style="margin-top: 10px;">去提交游戏</a></p>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <table class="profile-list-table">
                                    <thead>
                                        <tr>
                                            <th>标题</th>
                                            <th>VNDB ID</th>
                                            <?php if ($isOwner): ?><th>状态</th><?php endif; ?>
                                            <th>提交时间</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($tabData['games'] as $g): ?>
                                        <tr>
                                            <td><?php echo hs($g['title']); ?></td>
                                            <td><?php echo h($g['vndb_id'] ?? ''); ?></td>
                                            <?php if ($isOwner): ?><td><?php echo statusLabel($g['status']); ?></td><?php endif; ?>
                                            <td><?php echo date('Y-m-d', strtotime($g['created_at'])); ?></td>
                                            <?php if ($isOwner): ?>
                                            <td>
                                                <?php if ($g['status'] === 'approved'): ?>
                                                    <a href="<?php echo urlGame($g['id']); ?>">查看</a>
                                                <?php endif; ?>
                                                <a href="/profile?tab=games&action=edit&id=<?php echo $g['id']; ?>">编辑</a>
                                            </td>
                                            <?php else: ?>
                                            <td>
                                                <?php if ($g['status'] === 'approved'): ?>
                                                    <a href="<?php echo urlGame($g['id']); ?>">查看</a>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>

                                <?php if ($tabData['pagination']['totalPages'] > 1): ?>
                                <div class="profile-pagination">
                                    <?php $p = $tabData['pagination']; ?>
                                    <?php if ($p['hasPrev']): ?>
                                        <a href="/profile?tab=games&page=<?php echo $p['page'] - 1; ?><?php echo $userIdParam; ?>">&laquo; 上一页</a>
                                    <?php endif; ?>
                                    <?php for ($i = 1; $i <= $p['totalPages']; $i++): ?>
                                        <?php if ($i === $p['page']): ?>
                                            <span class="current"><?php echo $i; ?></span>
                                        <?php else: ?>
                                            <a href="/profile?tab=games&page=<?php echo $i; ?><?php echo $userIdParam; ?>"><?php echo $i; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    <?php if ($p['hasNext']): ?>
                                        <a href="/profile?tab=games&page=<?php echo $p['page'] + 1; ?><?php echo $userIdParam; ?>">下一页 &raquo;</a>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php elseif ($tab === 'errors'): ?>
                    <!-- Tab 4: 报错管理 -->
                    <?php if ($getAction === 'edit' && !empty($tabData['edit_error'])): ?>
                        <?php $e = $tabData['edit_error']; ?>
                        <div class="profile-card">
                            <h2>编辑报错</h2>
                            <form method="post">
                                <input type="hidden" name="action" value="edit_error">
                                <input type="hidden" name="error_id" value="<?php echo $e['id']; ?>">
                                <div class="form-group">
                                    <label class="form-label">所属游戏</label>
                                    <input type="text" class="form-input" value="<?php echo hs($e['game_title'] ?? '未知游戏'); ?>" disabled>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">报错分类 *</label>
                                    <select name="category_id" class="form-select" required>
                                        <option value="">请选择分类</option>
                                        <?php foreach ($tabData['categories'] as $cat): ?>
                                            <option value="<?php echo $cat['id']; ?>" <?php echo $cat['id'] == $e['category_id'] ? 'selected' : ''; ?>>
                                                <?php echo h($cat['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">报错标题 *</label>
                                    <input type="text" name="title" class="form-input" required value="<?php echo h($e['title']); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">错误现象</label>
                                    <textarea name="phenomenon" class="form-textarea" rows="4"><?php echo h($e['phenomenon'] ?? ''); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">系统信息</label>
                                    <input type="text" name="system_info" class="form-input" value="<?php echo h($e['system_info'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">汉化补丁</label>
                                    <input type="text" name="patch_info" class="form-input" value="<?php echo h($e['patch_info'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">解决方案</label>
                                    <textarea name="solution" class="form-textarea" rows="6"><?php echo h($e['solution'] ?? ''); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <p class="form-hint" style="color: #fbbf24;">提交后报错状态将变为"待审核"，需管理员重新审核通过后才会显示。</p>
                                </div>
                                <div class="btn-group">
                                    <button type="submit" class="btn">保存修改</button>
                                    <a href="/profile?tab=errors" class="btn btn-secondary">返回列表</a>
                                </div>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="profile-card">
                            <h2><?php echo $isOwner ? '我的报错' : h($currentUser['username']) . ' 的报错'; ?></h2>
                            <?php if (empty($tabData['errors'])): ?>
                                <div class="empty-state">
                                    <p><?php echo $isOwner ? '您还没有提交过报错' : '该用户还没有报错'; ?></p>
                                    <?php if ($isOwner): ?>
                                    <p><a href="/submit" class="btn" style="margin-top: 10px;">去提交报错</a></p>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <table class="profile-list-table">
                                    <thead>
                                        <tr>
                                            <th>标题</th>
                                            <th>所属游戏</th>
                                            <?php if ($isOwner): ?><th>状态</th><?php endif; ?>
                                            <th>提交时间</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($tabData['errors'] as $e): ?>
                                        <tr>
                                            <td><?php echo h($e['title']); ?></td>
                                            <td><?php echo hs($e['game_title'] ?? '未知游戏'); ?></td>
                                            <?php if ($isOwner): ?><td><?php echo statusLabel($e['status']); ?></td><?php endif; ?>
                                            <td><?php echo date('Y-m-d', strtotime($e['created_at'])); ?></td>
                                            <?php if ($isOwner): ?>
                                            <td>
                                                <?php if ($e['status'] === 'approved'): ?>
                                                    <a href="<?php echo urlError($e['id']); ?>">查看</a>
                                                <?php endif; ?>
                                                <a href="/profile?tab=errors&action=edit&id=<?php echo $e['id']; ?>">编辑</a>
                                            </td>
                                            <?php else: ?>
                                            <td>
                                                <?php if ($e['status'] === 'approved'): ?>
                                                    <a href="<?php echo urlError($e['id']); ?>">查看</a>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>

                                <?php if ($tabData['pagination']['totalPages'] > 1): ?>
                                <div class="profile-pagination">
                                    <?php $p = $tabData['pagination']; ?>
                                    <?php if ($p['hasPrev']): ?>
                                        <a href="/profile?tab=errors&page=<?php echo $p['page'] - 1; ?><?php echo $userIdParam; ?>">&laquo; 上一页</a>
                                    <?php endif; ?>
                                    <?php for ($i = 1; $i <= $p['totalPages']; $i++): ?>
                                        <?php if ($i === $p['page']): ?>
                                            <span class="current"><?php echo $i; ?></span>
                                        <?php else: ?>
                                            <a href="/profile?tab=errors&page=<?php echo $i; ?><?php echo $userIdParam; ?>"><?php echo $i; ?></a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    <?php if ($p['hasNext']): ?>
                                        <a href="/profile?tab=errors&page=<?php echo $p['page'] + 1; ?><?php echo $userIdParam; ?>">下一页 &raquo;</a>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php elseif ($tab === 'articles'): ?>
                    <!-- Tab 5: 文章管理 -->
                    <div class="profile-card">
                        <h2><?php echo $isOwner ? '我的文章' : h($currentUser['username']) . ' 的文章'; ?></h2>
                        <?php if (empty($tabData['articles'])): ?>
                            <div class="empty-state">
                                <p><?php echo $isOwner ? '您还没有提交过文章' : '该用户还没有文章'; ?></p>
                                <?php if ($isOwner): ?>
                                <p><a href="/submit_article" class="btn" style="margin-top: 10px;">去提交文章</a></p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <table class="profile-list-table">
                                <thead>
                                    <tr>
                                        <th>标题</th>
                                        <th>标签</th>
                                        <?php if ($isOwner): ?><th>状态</th><?php endif; ?>
                                        <th>提交时间</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tabData['articles'] as $art): ?>
                                    <tr>
                                        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                            <?php echo h(mb_substr($art['title'], 0, 30)); ?>
                                        </td>
                                        <td>
                                            <?php $artTags = array_filter(array_map('trim', explode(',', $art['tags']))); ?>
                                            <?php foreach (array_slice($artTags, 0, 2) as $t): ?>
                                                <span class="article-tag-sm"><?php echo h($t); ?></span>
                                            <?php endforeach; ?>
                                        </td>
                                        <?php if ($isOwner): ?>
                                        <td>
                                            <?php echo statusLabel($art['status']); ?>
                                            <?php if ($art['status'] === 'rejected' && $art['reject_reason']): ?>
                                                <div style="font-size:0.75rem;color:var(--accent-red);margin-top:2px;">
                                                    <?php echo h(mb_substr($art['reject_reason'], 0, 20)); ?>...
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        <td><?php echo date('Y-m-d', strtotime($art['created_at'])); ?></td>
                                        <td>
                                            <?php if ($art['status'] === 'approved'): ?>
                                                <a href="<?php echo urlArticle($art['id']); ?>">查看</a>
                                            <?php endif; ?>
                                            <?php if ($isOwner): ?>
                                                <a href="/submit_article?edit=<?php echo $art['id']; ?>">编辑</a>
                                            <?php endif; ?>
                                            <?php if ($art['status'] !== 'approved' && !$isOwner): ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <?php if ($tabData['pagination']['totalPages'] > 1): ?>
                            <div class="profile-pagination">
                                <?php $p = $tabData['pagination']; ?>
                                <?php if ($p['hasPrev']): ?>
                                    <a href="/profile?tab=articles&page=<?php echo $p['page'] - 1; ?><?php echo $userIdParam; ?>">&laquo; 上一页</a>
                                <?php endif; ?>
                                <?php for ($i = 1; $i <= $p['totalPages']; $i++): ?>
                                    <?php if ($i === $p['page']): ?>
                                        <span class="current"><?php echo $i; ?></span>
                                    <?php else: ?>
                                        <a href="/profile?tab=articles&page=<?php echo $i; ?><?php echo $userIdParam; ?>"><?php echo $i; ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                <?php if ($p['hasNext']): ?>
                                    <a href="/profile?tab=articles&page=<?php echo $p['page'] + 1; ?><?php echo $userIdParam; ?>">下一页 &raquo;</a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <?php elseif ($tab === 'discussions'): ?>
                    <!-- Tab 6: 话题管理 -->
                    <div class="profile-card">
                        <h2><?php echo $isOwner ? '我的话题' : h($currentUser['username']) . ' 的话题'; ?></h2>
                        <?php if (empty($tabData['discussions'])): ?>
                            <div class="empty-state">
                                <p><?php echo $isOwner ? '您还没有发布过话题' : '该用户还没有话题'; ?></p>
                                <?php if ($isOwner): ?>
                                <p><a href="/submit_discussion" class="btn" style="margin-top: 10px;">去发布话题</a></p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <table class="profile-list-table">
                                <thead>
                                    <tr>
                                        <th>标题</th>
                                        <th>标签</th>
                                        <?php if ($isOwner): ?><th>状态</th><?php endif; ?>
                                        <th>提交时间</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tabData['discussions'] as $disc): ?>
                                    <tr>
                                        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                            <?php echo h(mb_substr($disc['title'], 0, 30)); ?>
                                        </td>
                                        <td>
                                            <?php $discTags = array_filter(array_map('trim', explode(',', $disc['tags']))); ?>
                                            <?php foreach (array_slice($discTags, 0, 2) as $t): ?>
                                                <span class="article-tag-sm"><?php echo h($t); ?></span>
                                            <?php endforeach; ?>
                                        </td>
                                        <?php if ($isOwner): ?>
                                        <td><?php echo statusLabel($disc['status']); ?></td>
                                        <?php endif; ?>
                                        <td><?php echo date('Y-m-d', strtotime($disc['created_at'])); ?></td>
                                        <td>
                                            <?php if ($disc['status'] === 'active'): ?>
                                                <a href="<?php echo urlDiscussion($disc['id']); ?>">查看</a>
                                            <?php endif; ?>
                                            <?php if ($isOwner && $disc['status'] === 'active'): ?>
                                                <a href="/submit_discussion?edit=<?php echo $disc['id']; ?>">编辑</a>
                                            <?php endif; ?>
                                            <?php if ($disc['status'] !== 'active' && !$isOwner): ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <?php if ($tabData['pagination']['totalPages'] > 1): ?>
                            <div class="profile-pagination">
                                <?php $p = $tabData['pagination']; ?>
                                <?php if ($p['hasPrev']): ?>
                                    <a href="/profile?tab=discussions&page=<?php echo $p['page'] - 1; ?><?php echo $userIdParam; ?>">&laquo; 上一页</a>
                                <?php endif; ?>
                                <?php for ($i = 1; $i <= $p['totalPages']; $i++): ?>
                                    <?php if ($i === $p['page']): ?>
                                        <span class="current"><?php echo $i; ?></span>
                                    <?php else: ?>
                                        <a href="/profile?tab=discussions&page=<?php echo $i; ?><?php echo $userIdParam; ?>"><?php echo $i; ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                <?php if ($p['hasNext']): ?>
                                    <a href="/profile?tab=discussions&page=<?php echo $p['page'] + 1; ?><?php echo $userIdParam; ?>">下一页 &raquo;</a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
    <?php if ($isOwner && $tab === 'info'): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
    <script src="/assets/js/cover-cropper.js?v=<?php echo ASSETS_VER; ?>"></script>
    <script>
    var currentAvatarFile = null;
    var avatarCropper = new CoverCropper({
        uploadUrl: '/profile?action=upload_cropped_avatar',
        croppedPathInput: 'cropped_avatar_path',
        cropStatusId: 'avatar_crop_status',
        aspectRatio: 1,
        outputWidth: 320,
        outputHeight: 320,
        outputQuality: 0.92,
        title: '裁剪头像',
        confirmText: '确认头像裁剪',
        previewShape: 'circle',
        onCropped: function(path, base64Data) {
            var statusEl = document.getElementById('avatar_crop_status');
            statusEl.style.display = 'inline-flex';
            statusEl.innerHTML = '<img class="crop-preview-thumb crop-preview-avatar" src="' + base64Data + '" alt=""> 头像已裁剪';
        }
    });

    document.getElementById('avatar_file').addEventListener('change', function() {
        currentAvatarFile = this.files && this.files[0] ? this.files[0] : null;
        document.getElementById('avatarCropBtn').style.display = currentAvatarFile ? '' : 'none';
        avatarCropper.reset();
    });

    function openAvatarCropper() {
        if (!currentAvatarFile) {
            alert('请先选择头像图片');
            return;
        }
        avatarCropper.open(currentAvatarFile, 'file');
    }

    document.getElementById('avatarForm').addEventListener('submit', function(e) {
        if (!document.getElementById('cropped_avatar_path').value) {
            e.preventDefault();
            alert('请先点击“裁剪头像”并确认裁剪后再上传');
        }
    });
    </script>
    <?php endif; ?>
</body>
</html>
