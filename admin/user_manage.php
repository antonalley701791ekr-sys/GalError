<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/mailer.php';

checkLogin();
requirePermission('users', 'view');

$pdo = getDB();
$message = '';
$messageType = '';

$isSuperAdmin = isSuperAdmin();

// 权限模块定义（与 users.php 保持一致）
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

$action = $_GET['action'] ?? '';
$onlineTimeoutMinutes = defined('ONLINE_TIMEOUT_MINUTES') ? max(1, (int)ONLINE_TIMEOUT_MINUTES) : 10;
$onlineTimeoutSeconds = $onlineTimeoutMinutes * 60;

// ===== AJAX 端点：在线状态快照 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'online_snapshot') {
    header('Content-Type: application/json; charset=utf-8');

    $input = json_decode(file_get_contents('php://input'), true);
    $rawIds = $input['user_ids'] ?? [];
    $userIds = [];

    if (is_array($rawIds)) {
        foreach ($rawIds as $id) {
            $id = intval($id);
            if ($id > 0) {
                $userIds[$id] = $id;
            }
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
            $statuses[(int)$row['id']] = [
                'last_activity_ts' => $ts,
                'is_online' => ($ts > 0 && $ts >= $onlineThreshold)
            ];
        }
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE enabled = 1 AND last_activity_at IS NOT NULL AND last_activity_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)");
    $countStmt->execute([$onlineTimeoutMinutes]);
    $onlineUsersCount = (int)$countStmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'server_now_ts' => time(),
        'timeout_seconds' => $onlineTimeoutSeconds,
        'online_users_count' => $onlineUsersCount,
        'statuses' => $statuses
    ]);
    exit;
}

// ===== AJAX 端点：单个邮箱验证 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'verify_email') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => '此功能仅超级管理员可访问']);
        exit;
    }

    // 服务端限流：10秒内仅可操作1次
    $lastVerify = $_SESSION['last_verify_time'] ?? 0;
    if (time() - $lastVerify < 10) {
        echo json_encode(['success' => false, 'message' => '操作过于频繁，请稍后再试']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $userId = intval($input['user_id'] ?? 0);

    if ($userId <= 0) {
        echo json_encode(['success' => false, 'message' => '无效的用户ID']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, username, email, role, email_verified FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $target = $stmt->fetch();

    if (!$target) {
        echo json_encode(['success' => false, 'message' => '用户不存在']);
        exit;
    }
    if ($target['role'] !== 'user') {
        echo json_encode(['success' => false, 'message' => '不能验证管理员账户']);
        exit;
    }
    if ($target['email_verified']) {
        echo json_encode(['success' => false, 'message' => '该用户已经验证过了']);
        exit;
    }

    $upStmt = $pdo->prepare("UPDATE users SET email_verified = 1, verify_token = NULL, verify_token_expires = NULL WHERE id = ? AND email_verified = 0");
    $upStmt->execute([$userId]);

    if ($upStmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => '操作未生效，用户可能已被其他管理员验证']);
        exit;
    }

    // 发送通知邮件（失败不阻断）
    $mailSent = false;
    if (!empty($target['email'])) {
        $mailSent = sendAccountVerifiedEmail($target['email'], $target['username']);
    }

    // 写入操作日志
    $adminId = $_SESSION['admin_id'] ?? 0;
    $adminUsername = $_SESSION['admin_username'] ?? 'unknown';
    $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, admin_username, action, target_user_id, target_username, detail, result, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $logStmt->execute([
        $adminId,
        $adminUsername,
        'manual_verify_email',
        $target['id'],
        $target['username'],
        json_encode(['email' => maskEmail($target['email']), 'mail_sent' => $mailSent], JSON_UNESCAPED_UNICODE),
        'success',
        getClientIP()
    ]);

    $_SESSION['last_verify_time'] = time();
    echo json_encode(['success' => true, 'message' => '验证成功，用户已激活']);
    exit;
}

// ===== AJAX 端点：批量邮箱验证 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'batch_verify') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => '此功能仅超级管理员可访问']);
        exit;
    }

    // 服务端限流：10秒内仅可操作1次
    $lastVerify = $_SESSION['last_verify_time'] ?? 0;
    if (time() - $lastVerify < 10) {
        echo json_encode(['success' => false, 'message' => '操作过于频繁，请稍后再试']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $userIds = $input['user_ids'] ?? [];

    if (!is_array($userIds) || empty($userIds)) {
        echo json_encode(['success' => false, 'message' => '请选择要验证的用户']);
        exit;
    }

    if (count($userIds) > 50) {
        echo json_encode(['success' => false, 'message' => '单次最多验证 50 个用户']);
        exit;
    }

    $adminId = $_SESSION['admin_id'] ?? 0;
    $adminUsername = $_SESSION['admin_username'] ?? 'unknown';
    $verified = 0;
    $skipped = 0;
    $mailQueue = []; // 先收集需要发邮件的用户，事务提交后再发

    $selectStmt = $pdo->prepare("SELECT id, username, email, role, email_verified FROM users WHERE id = ?");
    $upStmt = $pdo->prepare("UPDATE users SET email_verified = 1, verify_token = NULL, verify_token_expires = NULL WHERE id = ? AND email_verified = 0");
    $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, admin_username, action, target_user_id, target_username, detail, result, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    $pdo->beginTransaction();
    try {
        foreach ($userIds as $uid) {
            $uid = intval($uid);
            if ($uid <= 0) { $skipped++; continue; }

            $selectStmt->execute([$uid]);
            $target = $selectStmt->fetch();

            if (!$target || $target['role'] !== 'user' || $target['email_verified']) {
                $skipped++;
                continue;
            }

            $upStmt->execute([$uid]);
            if ($upStmt->rowCount() === 0) {
                $skipped++;
                continue;
            }

            // 记录待发邮件
            if (!empty($target['email'])) {
                $mailQueue[] = ['email' => $target['email'], 'username' => $target['username']];
            }

            // 写入日志（邮件状态稍后更新）
            $logStmt->execute([
                $adminId,
                $adminUsername,
                'batch_verify_email',
                $target['id'],
                $target['username'],
                json_encode(['email' => maskEmail($target['email'])], JSON_UNESCAPED_UNICODE),
                'success',
                getClientIP()
            ]);

            $verified++;
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '数据库操作失败，请重试']);
        exit;
    }

    // 事务提交后发送通知邮件（失败不影响验证结果）
    foreach ($mailQueue as $m) {
        sendAccountVerifiedEmail($m['email'], $m['username']);
    }

    $_SESSION['last_verify_time'] = time();
    echo json_encode([
        'success' => true,
        'message' => "批量验证完成：成功 {$verified} 个，跳过 {$skipped} 个",
        'verified' => $verified,
        'skipped' => $skipped
    ]);
    exit;
}

// ===== AJAX 端点：删除用户 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_user') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => '此功能仅超级管理员可访问']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $userId = intval($input['user_id'] ?? 0);

    if ($userId <= 0) {
        echo json_encode(['success' => false, 'message' => '无效的用户ID']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, username, email, role, avatar FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $target = $stmt->fetch();

    if (!$target) {
        echo json_encode(['success' => false, 'message' => '用户不存在']);
        exit;
    }
    if ($target['role'] !== 'user') {
        echo json_encode(['success' => false, 'message' => '不能删除管理员账户，请到管理员设置页面操作']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        // 删除关联数据
        $pdo->prepare("DELETE FROM messages WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("DELETE FROM articles WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("UPDATE errors SET user_id = NULL WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("UPDATE games SET user_id = NULL WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("UPDATE error_revisions SET user_id = NULL WHERE user_id = ?")->execute([$userId]);

        // 删除用户记录
        $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'user'")->execute([$userId]);

        // 写入操作日志
        $adminId = $_SESSION['admin_id'] ?? 0;
        $adminUsername = $_SESSION['admin_username'] ?? 'unknown';
        $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, admin_username, action, target_user_id, target_username, detail, result, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $logStmt->execute([
            $adminId,
            $adminUsername,
            'delete_user',
            $target['id'],
            $target['username'],
            json_encode(['email' => maskEmail($target['email'])], JSON_UNESCAPED_UNICODE),
            'success',
            getClientIP()
        ]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '删除失败：数据库操作出错']);
        exit;
    }

    // 事务成功后删除头像文件
    if (!empty($target['avatar'])) {
        $avatarPath = BASE_PATH . $target['avatar'];
        if (file_exists($avatarPath)) {
            @unlink($avatarPath);
        }
    }

    echo json_encode(['success' => true, 'message' => '用户【' . $target['username'] . '】已成功删除']);
    exit;
}

// ===== AJAX 端点：升级普通用户为子管理员 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upgrade_to_sub') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => '此功能仅超级管理员可访问']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $userId = intval($input['user_id'] ?? 0);
    $rawPerms = $input['permissions'] ?? [];

    if ($userId <= 0) {
        echo json_encode(['success' => false, 'message' => '无效的用户ID']);
        exit;
    }

    if (!is_array($rawPerms) || empty($rawPerms)) {
        echo json_encode(['success' => false, 'message' => '请至少选择一项权限']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, username, email, role, banned FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $target = $stmt->fetch();

    if (!$target) {
        echo json_encode(['success' => false, 'message' => '用户不存在']);
        exit;
    }
    if ($target['role'] !== 'user') {
        echo json_encode(['success' => false, 'message' => '该用户不是普通用户，无法执行此操作']);
        exit;
    }
    if (!empty($target['banned'])) {
        echo json_encode(['success' => false, 'message' => '该用户已被封禁，请先解封再升级']);
        exit;
    }

    // 清洗权限矩阵：只保留合法的 module + action
    $cleanPerms = [];
    foreach ($rawPerms as $mod => $actions) {
        if (!isset($permModules[$mod]) || !is_array($actions)) continue;
        $validActions = [];
        foreach ($actions as $act) {
            if (in_array($act, $permModules[$mod]['actions'], true)) {
                $validActions[] = $act;
            }
        }
        if (!empty($validActions)) {
            $cleanPerms[$mod] = $validActions;
        }
    }

    if (empty($cleanPerms)) {
        echo json_encode(['success' => false, 'message' => '请至少选择一项有效权限']);
        exit;
    }

    $permJson = json_encode($cleanPerms, JSON_UNESCAPED_UNICODE);

    $pdo->beginTransaction();
    try {
        $upStmt = $pdo->prepare("UPDATE users SET role = 'sub', permissions = ?, banned = 0, enabled = 1 WHERE id = ? AND role = 'user'");
        $upStmt->execute([$permJson, $userId]);

        if ($upStmt->rowCount() === 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => '操作未生效，用户状态可能已变更']);
            exit;
        }

        // 写入操作日志
        $adminId = $_SESSION['admin_id'] ?? 0;
        $adminUsername = $_SESSION['admin_username'] ?? 'unknown';
        $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, admin_username, action, target_user_id, target_username, detail, result, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $logStmt->execute([
            $adminId,
            $adminUsername,
            'upgrade_to_sub_admin',
            $target['id'],
            $target['username'],
            json_encode(['email' => maskEmail($target['email']), 'permissions' => $cleanPerms], JSON_UNESCAPED_UNICODE),
            'success',
            getClientIP()
        ]);

        $pdo->commit();

        // 发送管理员须知站内信
        sendNotification($userId, '恭喜成为管理员！请阅读管理员须知', '您已被提升为管理员，请务必阅读管理员须知，了解管理职责和操作规范。');
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '操作失败：数据库错误']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => '用户【' . $target['username'] . '】已成功升级为子管理员']);
    exit;
}

// ===== AJAX 端点：管理员邮箱手动验证 =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'verify_admin_email') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$isSuperAdmin) {
        echo json_encode(['success' => false, 'message' => '此功能仅超级管理员可访问']);
        exit;
    }

    $lastVerify = $_SESSION['last_admin_verify_time'] ?? 0;
    if (time() - $lastVerify < 10) {
        echo json_encode(['success' => false, 'message' => '操作过于频繁，请稍后再试']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $adminTargetId = intval($input['admin_id'] ?? 0);

    if ($adminTargetId <= 0) {
        echo json_encode(['success' => false, 'message' => '无效的管理员ID']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, username, email, role, email_verified FROM users WHERE id = ?");
    $stmt->execute([$adminTargetId]);
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
    $upStmt->execute([$adminTargetId]);

    if ($upStmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => '操作未生效，可能已被其他管理员验证']);
        exit;
    }

    $mailSent = false;
    if (!empty($target['email'])) {
        $mailSent = sendAccountVerifiedEmail($target['email'], $target['username']);
    }

    $adminId = $_SESSION['admin_id'] ?? 0;
    $adminUsername = $_SESSION['admin_username'] ?? 'unknown';
    $logStmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, admin_username, action, target_user_id, target_username, detail, result, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $logStmt->execute([
        $adminId,
        $adminUsername,
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

// ===== 封禁用户（保留原有逻辑） =====
if ($action === 'ban' && isset($_GET['id'])) {
    requirePermission('users', 'edit');
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) {
        $message = '用户不存在';
        $messageType = 'error';
    } elseif ($target['role'] !== 'user') {
        $message = '不能封禁管理员账户';
        $messageType = 'error';
    } else {
        $pdo->prepare("UPDATE users SET banned = 1 WHERE id = ?")->execute([$id]);
        $message = '用户已封禁';
        $messageType = 'success';
    }
    $action = '';
}

// ===== 解封用户（保留原有逻辑） =====
if ($action === 'unban' && isset($_GET['id'])) {
    requirePermission('users', 'edit');
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if (!$target) {
        $message = '用户不存在';
        $messageType = 'error';
    } else {
        $pdo->prepare("UPDATE users SET banned = 0 WHERE id = ?")->execute([$id]);
        $message = '用户已解封';
        $messageType = 'success';
    }
    $action = '';
}

// 搜索参数
$searchType = $_GET['search_type'] ?? '';
$searchKeyword = trim($_GET['keyword'] ?? '');
$roleFilter = $_GET['role_filter'] ?? '';

// 构建查询
$where = [];
$params = [];

if ($roleFilter === 'user') {
    $where[] = "role = 'user'";
} elseif ($roleFilter === 'sub') {
    $where[] = "role = 'sub'";
} elseif ($roleFilter === 'super') {
    $where[] = "role = 'super'";
} elseif ($roleFilter === 'admin') {
    $where[] = "role IN ('sub','super')";
}

if (!empty($searchKeyword)) {
    if ($searchType === 'id') {
        $where[] = "id = ?";
        $params[] = intval($searchKeyword);
    } elseif ($searchType === 'username_exact') {
        $where[] = "username = ?";
        $params[] = $searchKeyword;
    } else {
        // 默认模糊搜索用户名
        $where[] = "username LIKE ?";
        $params[] = '%' . $searchKeyword . '%';
    }
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// 分页
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("SELECT COUNT(*) as c FROM users $whereClause");
$countStmt->execute($params);
$total = $countStmt->fetch()['c'];
$pagination = paginate($total, $page, $perPage);

$sql = "SELECT id, username, email, role, avatar, enabled, banned, email_verified, permissions, created_at, last_activity_at 
        FROM users $whereClause 
        ORDER BY id DESC 
        LIMIT $offset, $perPage";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$userList = $stmt->fetchAll();

// 统计
$totalUsers = $pdo->query("SELECT COUNT(*) as c FROM users WHERE role = 'user'")->fetch()['c'];
$totalAdmins = $pdo->query("SELECT COUNT(*) as c FROM users WHERE role IN ('sub','super')")->fetch()['c'];
$bannedUsers = $pdo->query("SELECT COUNT(*) as c FROM users WHERE role = 'user' AND banned = 1")->fetch()['c'];
$activeUsers = $totalUsers - $bannedUsers;
$unverifiedUsers = $pdo->query("SELECT COUNT(*) as c FROM users WHERE role = 'user' AND email_verified = 0")->fetch()['c'];
$onlineUsers = $pdo->prepare("SELECT COUNT(*) as c FROM users WHERE enabled = 1 AND last_activity_at IS NOT NULL AND last_activity_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)");
$onlineUsers->execute([$onlineTimeoutMinutes]);
$onlineUsersCount = (int)$onlineUsers->fetch()['c'];

// 构建搜索参数的 URL 片段
$searchParams = '';
if (!empty($searchKeyword)) {
    $searchParams .= '&search_type=' . urlencode($searchType) . '&keyword=' . urlencode($searchKeyword);
}
if (!empty($roleFilter)) {
    $searchParams .= '&role_filter=' . urlencode($roleFilter);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>用户管理 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <style>
        .user-online-status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.84rem;
            line-height: 1;
            white-space: nowrap;
        }
        .user-online-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            display: inline-block;
        }
        .user-online-status.is-online {
            color: #22c55e;
        }
        .user-online-status.is-online .user-online-dot {
            background: #22c55e;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.16);
        }
        .user-online-status.is-offline {
            color: #6b7280;
        }
        .user-online-status.is-offline .user-online-dot {
            background: #6b7280;
            box-shadow: 0 0 0 3px rgba(107, 114, 128, 0.14);
        }
    </style>
    <?php renderAdminHeadScript(); ?>
</head>
<body class="has-fixed-nav">
    <?php include '../includes/header.php'; ?>
    <div class="admin-layout">
        <?php renderAdminSidebar('user_manage.php'); ?>

        <div class="admin-content">
            <main class="admin-main">
                <div class="admin-page-header">
                    <h1>用户管理</h1>
                    <div style="display:flex;align-items:center;gap:8px;color:var(--text-secondary);font-size:0.9rem;">
                        <span>在线用户</span>
                        <span id="onlineUsersCount" style="color: var(--accent-green, #34d399); font-weight: 700;"><?php echo $onlineUsersCount; ?></span>
                    </div>
                </div>

                <div id="messageArea">
                <?php if ($message): ?>
                    <div class="admin-alert-<?php echo $messageType; ?>">
                        <?php echo h($message); ?>
                    </div>
                <?php endif; ?>
                </div>

                <!-- 统计卡片 -->
                <div class="admin-stats-grid">
                    <div class="card stat-card">
                        <div class="card-body">
                            <h3 class="stat-number stat-number--cyan"><?php echo $totalUsers; ?></h3>
                            <p class="stat-label">普通用户</p>
                        </div>
                    </div>
                    <div class="card stat-card">
                        <div class="card-body">
                            <h3 class="stat-number" style="color: var(--accent-purple, #8b5cf6);"><?php echo $totalAdmins; ?></h3>
                            <p class="stat-label">管理员</p>
                        </div>
                    </div>
                    <div class="card stat-card">
                        <div class="card-body">
                            <h3 class="stat-number stat-number--green"><?php echo $activeUsers; ?></h3>
                            <p class="stat-label">正常用户</p>
                        </div>
                    </div>
                    <div class="card stat-card">
                        <div class="card-body">
                            <h3 class="stat-number stat-number--red"><?php echo $bannedUsers; ?></h3>
                            <p class="stat-label">已封禁</p>
                        </div>
                    </div>
                    <div class="card stat-card">
                        <div class="card-body">
                            <h3 class="stat-number stat-number--orange"><?php echo $unverifiedUsers; ?></h3>
                            <p class="stat-label">未验证</p>
                        </div>
                    </div>
                </div>

                <!-- 搜索 -->
                <div class="card" style="margin-bottom: 20px;">
                    <div class="card-body" style="padding: 16px;">
                        <form method="get" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                            <div>
                                <label class="form-label" style="margin-bottom: 4px; font-size: 0.82rem;">角色筛选</label>
                                <select name="role_filter" class="form-input" style="width: auto; min-width: 120px;">
                                    <option value="" <?php echo $roleFilter === '' ? 'selected' : ''; ?>>全部角色</option>
                                    <option value="user" <?php echo $roleFilter === 'user' ? 'selected' : ''; ?>>普通用户</option>
                                    <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>管理员</option>
                                    <option value="sub" <?php echo $roleFilter === 'sub' ? 'selected' : ''; ?>>子管理员</option>
                                    <option value="super" <?php echo $roleFilter === 'super' ? 'selected' : ''; ?>>超级管理员</option>
                                </select>
                            </div>
                            <div>
                                <label class="form-label" style="margin-bottom: 4px; font-size: 0.82rem;">搜索方式</label>
                                <select name="search_type" class="form-input" style="width: auto; min-width: 140px;">
                                    <option value="username" <?php echo $searchType === 'username' || $searchType === '' ? 'selected' : ''; ?>>用户名（模糊）</option>
                                    <option value="username_exact" <?php echo $searchType === 'username_exact' ? 'selected' : ''; ?>>用户名（精确）</option>
                                    <option value="id" <?php echo $searchType === 'id' ? 'selected' : ''; ?>>用户 ID</option>
                                </select>
                            </div>
                            <div style="flex: 1; min-width: 200px;">
                                <label class="form-label" style="margin-bottom: 4px; font-size: 0.82rem;">关键词</label>
                                <input type="text" name="keyword" class="form-input" placeholder="输入搜索关键词" value="<?php echo h($searchKeyword); ?>">
                            </div>
                            <div>
                                <button type="submit" class="btn" style="margin-bottom: 0;">搜索</button>
                                <?php if (!empty($searchKeyword) || !empty($roleFilter)): ?>
                                    <a href="user_manage.php" class="btn btn-secondary" style="margin-bottom: 0;">清除</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- 用户列表 -->
                <div class="card">
                    <div class="card-header card-header-actions">
                        <div class="card-header-left">
                            <span>用户列表</span>
                            <?php if ($isSuperAdmin): ?>
                                <button id="batchVerifyBtn" class="btn btn-verify btn-sm" style="display:none;">批量验证 (<span id="selectedCount">0</span>)</button>
                            <?php endif; ?>
                        </div>
                        <span class="text-muted" style="font-weight: normal;">
                            共 <?php echo $total; ?> 条记录
                            <?php if (!empty($searchKeyword)): ?>
                                （搜索：<?php echo h($searchKeyword); ?>）
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($userList)): ?>
                            <p class="text-muted" style="text-align: center; padding: 20px;">暂无用户数据</p>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table compact-mobile-list">
                                <thead>
                                    <tr>
                                        <?php if ($isSuperAdmin): ?>
                                            <th style="width: 40px;"><input type="checkbox" id="selectAll" class="user-select-checkbox" title="全选/取消"></th>
                                        <?php endif; ?>
                                        <th>ID</th>
                                        <th>用户名</th>
                                        <th>角色</th>
                                        <th>邮箱</th>
                                        <th>邮箱验证</th>
                                        <th>状态</th>
                                        <th>注册时间</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($userList as $u): ?>
                                        <?php $isAdmin = ($u['role'] !== 'user'); ?>
                                        <tr>
                                            <?php if ($isSuperAdmin): ?>
                                                <td>
                                                    <?php if (!$isAdmin && !$u['email_verified']): ?>
                                                        <input type="checkbox" class="user-select-checkbox" value="<?php echo $u['id']; ?>" data-username="<?php echo h($u['username']); ?>" data-email="<?php echo h(maskEmail($u['email'])); ?>">
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>
                                            <td><?php echo $u['id']; ?></td>
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 8px;">
                                                    <?php if ($u['avatar'] && file_exists(BASE_PATH . $u['avatar'])): ?>
                                                        <img src="/<?php echo h($u['avatar']); ?>" style="width: 28px; height: 28px; border-radius: 50%; object-fit: cover; border: 1px solid var(--glass-border);" alt="">
                                                    <?php endif; ?>
                                                    <a href="/profile?user_id=<?php echo $u['id']; ?>" style="color: var(--text-primary); text-decoration: none;">
                                                        <strong><?php echo h($u['username']); ?></strong>
                                                    </a>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($u['role'] === 'super'): ?>
                                                    <span class="status" style="background: rgba(234,179,8,0.15); color: #eab308; border-color: rgba(234,179,8,0.3);">超级管理员</span>
                                                <?php elseif ($u['role'] === 'sub'): ?>
                                                    <span class="status" style="background: rgba(139,92,246,0.15); color: #8b5cf6; border-color: rgba(139,92,246,0.3);">子管理员</span>
                                                    <?php
                                                        $perms = json_decode($u['permissions'] ?? '{}', true);
                                                        if (!empty($perms) && is_array($perms)):
                                                            $permLabels = [];
                                                            foreach ($perms as $mod => $acts) {
                                                                if (isset($permModules[$mod])) $permLabels[] = $permModules[$mod]['label'];
                                                            }
                                                    ?>
                                                        <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 2px;" title="<?php echo h(implode('、', $permLabels)); ?>">
                                                            <?php echo count($permLabels); ?>项权限
                                                        </div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="status" style="background: rgba(100,116,139,0.12); color: var(--text-secondary); border-color: rgba(100,116,139,0.2);">普通用户</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="font-size: 0.85rem;"><?php echo h(maskEmail($u['email'])); ?></td>
                                            <td>
                                                <?php if ($u['email_verified']): ?>
                                                    <span class="status status-approved">已激活</span>
                                                <?php else: ?>
                                                    <span class="status status-unverified">未激活</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                    $lastActivityTs = !empty($u['last_activity_at']) ? strtotime($u['last_activity_at']) : 0;
                                                    $isOnline = $lastActivityTs > 0 && (time() - $lastActivityTs) <= $onlineTimeoutSeconds;
                                                ?>
                                                <?php if ($isAdmin): ?>
                                                    <?php if ($u['enabled']): ?>
                                                        <span class="status status-approved">已启用</span>
                                                    <?php else: ?>
                                                        <span class="status status-disabled">已禁用</span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <?php if (!empty($u['banned'])): ?>
                                                        <span class="status status-rejected">已封禁</span>
                                                    <?php else: ?>
                                                        <span class="status status-approved">正常</span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                <span class="user-online-status <?php echo $isOnline ? 'is-online' : 'is-offline'; ?>" data-online-status="1" data-user-id="<?php echo (int)$u['id']; ?>" data-last-activity-ts="<?php echo (int)$lastActivityTs; ?>" style="margin-left: 8px;">
                                                    <span class="user-online-dot" aria-hidden="true"></span>
                                                    <span class="user-online-text"><?php echo $isOnline ? '在线' : '离线'; ?></span>
                                                </span>
                                            </td>
                                            <td><?php echo date('Y-m-d H:i', strtotime($u['created_at'])); ?></td>
                                            <td>
                                                <div class="action-btns">
                                                <?php if ($isAdmin): ?>
                                                    <?php if ($u['role'] === 'super'): ?>
                                                        <span class="text-muted" style="font-size: 12px;">超管不可操作</span>
                                                    <?php elseif ($isSuperAdmin): ?>
                                                        <?php if (!$u['email_verified'] && !empty($u['email'])): ?>
                                                            <button type="button" class="btn btn-verify btn-admin-verify" style="font-size: 12px; padding: 4px 10px;" data-id="<?php echo $u['id']; ?>" data-username="<?php echo h($u['username']); ?>" data-email="<?php echo h(maskEmail($u['email'])); ?>">邮箱验证</button>
                                                        <?php endif; ?>
                                                        <a href="users.php?action=edit&id=<?php echo $u['id']; ?>" class="btn btn-sm" style="font-size: 12px; padding: 4px 10px;">编辑权限</a>
                                                        <a href="users.php?action=toggle&id=<?php echo $u['id']; ?>" class="btn btn-sm btn-secondary" style="font-size: 12px; padding: 4px 10px;" onclick="return confirm('确定<?php echo $u['enabled'] ? '禁用' : '启用'; ?>此管理员？')"><?php echo $u['enabled'] ? '禁用' : '启用'; ?></a>
                                                        <a href="users.php?action=reset_password&id=<?php echo $u['id']; ?>" class="btn btn-sm btn-secondary" style="font-size: 12px; padding: 4px 10px;">重置密码</a>
                                                        <a href="users.php?action=delete&id=<?php echo $u['id']; ?>" class="btn btn-sm btn-danger" style="font-size: 12px; padding: 4px 10px;" onclick="return confirm('确定删除此管理员？此操作不可恢复！')">删除</a>
                                                    <?php else: ?>
                                                        <a href="users.php" class="btn btn-sm" style="font-size: 12px; padding: 4px 10px; background: var(--accent-purple); color: var(--bg-primary); border: 1px solid var(--glass-border); text-decoration: none;">管理员设置</a>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <?php if (!$u['email_verified']): ?>
                                                        <?php if ($isSuperAdmin): ?>
                                                            <button type="button" class="btn btn-verify btn-single-verify" style="font-size: 12px; padding: 4px 10px;" data-id="<?php echo $u['id']; ?>" data-username="<?php echo h($u['username']); ?>" data-email="<?php echo h(maskEmail($u['email'])); ?>">邮箱验证</button>
                                                        <?php else: ?>
                                                            <button type="button" class="btn btn-verify perm-disabled" style="font-size: 12px; padding: 4px 10px;" title="无权限" disabled>邮箱验证</button>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    <?php if ($isSuperAdmin && empty($u['banned'])): ?>
                                                        <button type="button" class="btn btn-promote-user" style="font-size: 12px; padding: 4px 10px; background: var(--accent-purple); color: var(--bg-primary); border: 1px solid var(--glass-border);" data-id="<?php echo $u['id']; ?>" data-username="<?php echo h($u['username']); ?>" data-email="<?php echo h(maskEmail($u['email'])); ?>">升级管理</button>
                                                    <?php elseif (!$isSuperAdmin && empty($u['banned'])): ?>
                                                        <button type="button" class="btn perm-disabled" style="font-size: 12px; padding: 4px 10px; background: var(--accent-purple); color: var(--bg-primary); border: 1px solid var(--glass-border);" title="无权限" disabled>升级管理</button>
                                                    <?php endif; ?>
                                                    <?php if (!empty($u['banned'])): ?>
                                                        <a href="?action=unban&id=<?php echo $u['id']; ?><?php echo $searchParams; ?>" class="btn btn-success<?php echo pd('users','edit'); ?>" style="font-size: 12px; padding: 4px 10px;"<?php echo pdAttr('users','edit'); ?> onclick="<?php echo hasPermission('users','edit') ? "return confirm('确定解封此用户？')" : 'return false;'; ?>">解封</a>
                                                    <?php else: ?>
                                                        <a href="?action=ban&id=<?php echo $u['id']; ?><?php echo $searchParams; ?>" class="btn btn-danger<?php echo pd('users','edit'); ?>" style="font-size: 12px; padding: 4px 10px;"<?php echo pdAttr('users','edit'); ?> onclick="<?php echo hasPermission('users','edit') ? "return confirm('确定封禁此用户？封禁后用户仍可登录查看页面，但无法进行任何提交和修改操作。')" : 'return false;'; ?>">封禁</a>
                                                    <?php endif; ?>
                                                    <?php if ($isSuperAdmin): ?>
                                                        <button type="button" class="btn btn-danger btn-delete-user" style="font-size: 12px; padding: 4px 10px;" data-id="<?php echo $u['id']; ?>" data-username="<?php echo h($u['username']); ?>">删除</button>
                                                    <?php else: ?>
                                                        <button type="button" class="btn btn-danger perm-disabled" style="font-size: 12px; padding: 4px 10px;" title="无权限" disabled>删除</button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- 分页 -->
                        <?php if ($pagination['totalPages'] > 1): ?>
                            <div class="pagination">
                                <?php if ($pagination['page'] > 1): ?>
                                    <a href="?page=1<?php echo $searchParams; ?>">第一页</a>
                                <?php endif; ?>
                                <?php if ($pagination['hasPrev']): ?>
                                    <a href="?page=<?php echo $pagination['page'] - 1; ?><?php echo $searchParams; ?>">上一页</a>
                                <?php endif; ?>
                                <?php for ($i = max(1, $pagination['page'] - 2); $i <= min($pagination['totalPages'], $pagination['page'] + 2); $i++): ?>
                                    <?php if ($i == $pagination['page']): ?>
                                        <span class="current"><?php echo $i; ?></span>
                                    <?php else: ?>
                                        <a href="?page=<?php echo $i; ?><?php echo $searchParams; ?>"><?php echo $i; ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                <?php if ($pagination['hasNext']): ?>
                                    <a href="?page=<?php echo $pagination['page'] + 1; ?><?php echo $searchParams; ?>">下一页</a>
                                <?php endif; ?>
                                <?php if ($pagination['page'] < $pagination['totalPages']): ?>
                                    <a href="?page=<?php echo $pagination['totalPages']; ?><?php echo $searchParams; ?>">最后一页</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
    (function() {
        var ONLINE_REFRESH_MS = 30000;
        var onlineRefreshTimer = null;
        var onlineCountEl = document.getElementById('onlineUsersCount');

        function setOnlineNodeStatus(node, isOnline, lastActivityTs) {
            if (!node) return;
            node.classList.remove('is-online', 'is-offline');
            node.classList.add(isOnline ? 'is-online' : 'is-offline');
            node.setAttribute('data-last-activity-ts', String(lastActivityTs || 0));
            var textEl = node.querySelector('.user-online-text');
            if (textEl) {
                textEl.textContent = isOnline ? '在线' : '离线';
            }
        }

        function refreshOnlineSnapshot() {
            var nodes = document.querySelectorAll('[data-online-status][data-user-id]');
            if (!nodes.length) return;

            var userIds = [];
            nodes.forEach(function(node) {
                var uid = parseInt(node.getAttribute('data-user-id') || '0', 10);
                if (uid > 0) userIds.push(uid);
            });

            if (!userIds.length) return;

            fetch('?action=online_snapshot', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ user_ids: userIds })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (!data || !data.success) return;
                var map = data.statuses || {};
                nodes.forEach(function(node) {
                    var uid = String(parseInt(node.getAttribute('data-user-id') || '0', 10));
                    var status = map[uid];
                    if (!status) return;
                    setOnlineNodeStatus(node, !!status.is_online, parseInt(status.last_activity_ts || 0, 10));
                });
                if (onlineCountEl && typeof data.online_users_count !== 'undefined') {
                    onlineCountEl.textContent = String(data.online_users_count);
                }
            })
            .catch(function() {});
        }

        function startOnlineAutoRefresh() {
            refreshOnlineSnapshot();
            if (onlineRefreshTimer) {
                clearInterval(onlineRefreshTimer);
            }
            onlineRefreshTimer = setInterval(refreshOnlineSnapshot, ONLINE_REFRESH_MS);

            document.addEventListener('visibilitychange', function() {
                if (document.visibilityState === 'visible') {
                    refreshOnlineSnapshot();
                }
            });
        }

        startOnlineAutoRefresh();
    })();
    </script>

    <?php if ($isSuperAdmin): ?>
    <!-- 验证确认弹窗 -->
    <div class="verify-modal-overlay" id="verifyModal">
        <div class="verify-modal">
            <div class="verify-modal-header">
                <span class="verify-modal-title">确认手动验证</span>
                <button class="verify-modal-close" id="modalClose">&times;</button>
            </div>
            <div class="verify-modal-body" id="modalBody"></div>
            <div class="verify-modal-footer">
                <button class="btn btn-secondary btn-sm" id="modalCancel">取消</button>
                <button class="btn btn-verify btn-sm" id="modalConfirm">确认验证</button>
            </div>
        </div>
    </div>

    <!-- 删除确认弹窗 -->
    <div class="verify-modal-overlay" id="deleteModal">
        <div class="verify-modal">
            <div class="verify-modal-header">
                <span class="verify-modal-title" style="color: var(--danger-color, #e74c3c);">确认删除用户</span>
                <button class="verify-modal-close" id="deleteModalClose">&times;</button>
            </div>
            <div class="verify-modal-body" id="deleteModalBody"></div>
            <div class="verify-modal-footer">
                <button class="btn btn-secondary btn-sm" id="deleteModalCancel">取消</button>
                <button class="btn btn-danger btn-sm" id="deleteModalConfirm">确认删除</button>
            </div>
        </div>
    </div>

    <!-- 升级子管理员弹窗 -->
    <div class="verify-modal-overlay" id="upgradeModal">
        <div class="verify-modal" style="max-width: 640px;">
            <div class="verify-modal-header">
                <span class="verify-modal-title" style="color: var(--accent-purple, #8b5cf6);">升级为子管理员</span>
                <button class="verify-modal-close" id="upgradeModalClose">&times;</button>
            </div>
            <div class="verify-modal-body">
                <div id="upgradeUserInfo"></div>
                <ul style="font-size: 0.88rem; margin: 10px 0;">
                    <li>升级后该用户可以登录管理后台</li>
                    <li>升级后用户角色将变更为「子管理员」</li>
                    <li>可在「管理员设置」中进一步管理其权限</li>
                    <li>用户的邮箱和密码将保持不变</li>
                </ul>
                <div style="margin-top: 12px;">
                    <label class="form-label" style="margin-bottom: 8px;">权限矩阵</label>
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
                                                <input type="checkbox" name="upgrade_perm[<?php echo $mod; ?>][]" value="<?php echo $act; ?>">
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
            </div>
            <div class="verify-modal-footer">
                <button class="btn btn-secondary btn-sm" id="upgradeModalCancel">取消</button>
                <button class="btn btn-sm" id="upgradeModalConfirm" style="background: var(--accent-purple, #8b5cf6); color: #fff; border: none;">确认升级</button>
            </div>
        </div>
    </div>

    <script>
    (function() {
        'use strict';

        var DEBOUNCE_MS = 10000;
        var lastVerifyTime = 0;

        var overlay = document.getElementById('verifyModal');
        var modalBody = document.getElementById('modalBody');
        var modalConfirm = document.getElementById('modalConfirm');
        var modalCancel = document.getElementById('modalCancel');
        var modalClose = document.getElementById('modalClose');
        var batchBtn = document.getElementById('batchVerifyBtn');
        var selectedCountEl = document.getElementById('selectedCount');
        var selectAll = document.getElementById('selectAll');
        var messageArea = document.getElementById('messageArea');

        var pendingAction = null; // {type:'single'|'batch', userId:int, userIds:[], ...}

        // ===== 防抖检查 =====
        function checkDebounce() {
            var elapsed = Date.now() - lastVerifyTime;
            if (elapsed < DEBOUNCE_MS) {
                var wait = Math.ceil((DEBOUNCE_MS - elapsed) / 1000);
                showMessage('操作过于频繁，请等待 ' + wait + ' 秒', 'error');
                return false;
            }
            return true;
        }

        // ===== 消息提示 =====
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

        // ===== Modal 控制 =====
        function openModal(bodyHtml, action) {
            modalBody.innerHTML = bodyHtml;
            pendingAction = action;
            modalConfirm.disabled = false;
            modalConfirm.textContent = '确认验证';
            overlay.classList.add('active');
        }

        function closeModal() {
            overlay.classList.remove('active');
            pendingAction = null;
        }

        modalClose.addEventListener('click', closeModal);
        modalCancel.addEventListener('click', closeModal);
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) closeModal();
        });

        // ===== 单个验证按钮点击 =====
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.btn-single-verify');
            if (!btn) return;
            e.preventDefault();

            if (!checkDebounce()) return;

            var userId = btn.getAttribute('data-id');
            var username = btn.getAttribute('data-username');
            var email = btn.getAttribute('data-email');

            var html = '<p>确认给<strong>【' + escapeHtml(username) + '】</strong>的邮箱<strong>【' + escapeHtml(email) + '】</strong>完成验证？</p>'
                + '<ul><li>将用户邮箱标记为已验证</li><li>向用户发送验证成功通知邮件</li></ul>';

            openModal(html, {type: 'single', userId: userId});
        });

        // ===== 管理员邮箱验证按钮点击 =====
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.btn-admin-verify');
            if (!btn) return;
            e.preventDefault();

            if (!checkDebounce()) return;

            var adminId = btn.getAttribute('data-id');
            var username = btn.getAttribute('data-username');
            var email = btn.getAttribute('data-email');

            var html = '<p>确认给管理员<strong>【' + escapeHtml(username) + '】</strong>的邮箱<strong>【' + escapeHtml(email) + '】</strong>完成验证？</p>'
                + '<ul><li>将管理员邮箱标记为已验证</li><li>向管理员发送验证成功通知邮件</li></ul>';

            openModal(html, {type: 'admin_verify', adminId: adminId});
        });

        // ===== 批量验证按钮点击 =====
        if (batchBtn) {
            batchBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (!checkDebounce()) return;

                var checkboxes = document.querySelectorAll('.user-select-checkbox:checked:not(#selectAll)');
                if (checkboxes.length === 0) return;

                var ids = [];
                var names = [];
                checkboxes.forEach(function(cb) {
                    ids.push(cb.value);
                    names.push(cb.getAttribute('data-username'));
                });

                var listHtml = '';
                var showNames = names.slice(0, 10);
                showNames.forEach(function(n) {
                    listHtml += escapeHtml(n) + '<br>';
                });
                if (names.length > 10) {
                    listHtml += '...等 ' + names.length + ' 位用户';
                }

                var html = '<p>确认给以下 <strong>' + ids.length + '</strong> 位用户完成邮箱验证？</p>'
                    + '<div class="verify-modal-userlist">' + listHtml + '</div>'
                    + '<ul><li>将所有选中用户的邮箱标记为已验证</li><li>向每位用户发送验证成功通知邮件</li></ul>';

                openModal(html, {type: 'batch', userIds: ids});
            });
        }

        // ===== 确认按钮点击 =====
        modalConfirm.addEventListener('click', function() {
            if (!pendingAction || modalConfirm.disabled) return;

            modalConfirm.disabled = true;
            modalConfirm.textContent = '处理中...';

            var url, body;
            if (pendingAction.type === 'single') {
                url = '?action=verify_email';
                body = JSON.stringify({user_id: parseInt(pendingAction.userId)});
            } else if (pendingAction.type === 'admin_verify') {
                url = '?action=verify_admin_email';
                body = JSON.stringify({admin_id: parseInt(pendingAction.adminId)});
            } else {
                url = '?action=batch_verify';
                body = JSON.stringify({user_ids: pendingAction.userIds.map(Number)});
            }

            fetch(url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: body
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

        // ===== 全选/反选逻辑 =====
        if (selectAll) {
            selectAll.addEventListener('change', function() {
                var cbs = document.querySelectorAll('.user-select-checkbox:not(#selectAll)');
                cbs.forEach(function(cb) { cb.checked = selectAll.checked; });
                updateBatchBtn();
            });

            document.addEventListener('change', function(e) {
                if (e.target.classList.contains('user-select-checkbox') && e.target.id !== 'selectAll') {
                    updateBatchBtn();
                    updateSelectAllState();
                }
            });
        }

        function updateBatchBtn() {
            if (!batchBtn) return;
            var checked = document.querySelectorAll('.user-select-checkbox:checked:not(#selectAll)');
            selectedCountEl.textContent = checked.length;
            batchBtn.style.display = checked.length > 0 ? 'inline-block' : 'none';
        }

        function updateSelectAllState() {
            if (!selectAll) return;
            var all = document.querySelectorAll('.user-select-checkbox:not(#selectAll)');
            var checked = document.querySelectorAll('.user-select-checkbox:checked:not(#selectAll)');
            if (all.length === 0) {
                selectAll.checked = false;
                selectAll.indeterminate = false;
            } else if (checked.length === 0) {
                selectAll.checked = false;
                selectAll.indeterminate = false;
            } else if (checked.length === all.length) {
                selectAll.checked = true;
                selectAll.indeterminate = false;
            } else {
                selectAll.checked = false;
                selectAll.indeterminate = true;
            }
        }

        // ===== 工具函数 =====
        function escapeHtml(str) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }

        // ===== 删除用户逻辑 =====
        var deleteOverlay = document.getElementById('deleteModal');
        var deleteModalBody = document.getElementById('deleteModalBody');
        var deleteModalConfirm = document.getElementById('deleteModalConfirm');
        var deleteModalCancel = document.getElementById('deleteModalCancel');
        var deleteModalClose = document.getElementById('deleteModalClose');
        var pendingDeleteUserId = null;

        function openDeleteModal(userId, username) {
            var html = '<p>确定要删除用户 <strong style="color:var(--danger-color,#e74c3c);">【' + escapeHtml(username) + '】</strong>？</p>'
                + '<p style="color: var(--danger-color, #e74c3c); font-size: 0.9rem;">此操作不可恢复！将同时执行：</p>'
                + '<ul style="font-size: 0.88rem;">'
                + '<li>删除该用户的站内信和文章</li>'
                + '<li>清除该用户提交的报错/游戏中的用户关联</li>'
                + '<li>删除用户头像文件</li>'
                + '<li>删除用户账户</li>'
                + '</ul>';
            deleteModalBody.innerHTML = html;
            pendingDeleteUserId = userId;
            deleteModalConfirm.disabled = false;
            deleteModalConfirm.textContent = '确认删除';
            deleteOverlay.classList.add('active');
        }

        function closeDeleteModal() {
            deleteOverlay.classList.remove('active');
            pendingDeleteUserId = null;
        }

        deleteModalClose.addEventListener('click', closeDeleteModal);
        deleteModalCancel.addEventListener('click', closeDeleteModal);
        deleteOverlay.addEventListener('click', function(e) {
            if (e.target === deleteOverlay) closeDeleteModal();
        });

        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.btn-delete-user');
            if (!btn) return;
            e.preventDefault();
            openDeleteModal(btn.getAttribute('data-id'), btn.getAttribute('data-username'));
        });

        deleteModalConfirm.addEventListener('click', function() {
            if (!pendingDeleteUserId || deleteModalConfirm.disabled) return;

            deleteModalConfirm.disabled = true;
            deleteModalConfirm.textContent = '删除中...';

            fetch('?action=delete_user', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({user_id: parseInt(pendingDeleteUserId)})
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                closeDeleteModal();
                if (data.success) {
                    showMessage(data.message, 'success');
                    setTimeout(function() { location.reload(); }, 1000);
                } else {
                    showMessage(data.message, 'error');
                }
            })
            .catch(function(err) {
                closeDeleteModal();
                showMessage('请求失败，请重试', 'error');
            });
        });

        // ===== 升级子管理员逻辑 =====
        var upgradeOverlay = document.getElementById('upgradeModal');
        var upgradeUserInfo = document.getElementById('upgradeUserInfo');
        var upgradeModalConfirm = document.getElementById('upgradeModalConfirm');
        var upgradeModalCancel = document.getElementById('upgradeModalCancel');
        var upgradeModalClose = document.getElementById('upgradeModalClose');
        var pendingUpgradeUserId = null;

        function openUpgradeModal(userId, username, email) {
            upgradeUserInfo.innerHTML = '<p>确定将用户 <strong>【' + escapeHtml(username) + '】</strong>（邮箱：' + escapeHtml(email) + '）升级为子管理员？</p>';
            pendingUpgradeUserId = userId;
            // 清空所有权限勾选
            var cbs = upgradeOverlay.querySelectorAll('input[type="checkbox"]');
            cbs.forEach(function(cb) { cb.checked = false; });
            upgradeModalConfirm.disabled = false;
            upgradeModalConfirm.textContent = '确认升级';
            upgradeOverlay.classList.add('active');
        }

        function closeUpgradeModal() {
            upgradeOverlay.classList.remove('active');
            pendingUpgradeUserId = null;
        }

        upgradeModalClose.addEventListener('click', closeUpgradeModal);
        upgradeModalCancel.addEventListener('click', closeUpgradeModal);
        upgradeOverlay.addEventListener('click', function(e) {
            if (e.target === upgradeOverlay) closeUpgradeModal();
        });

        // 升级按钮点击
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.btn-promote-user');
            if (!btn) return;
            e.preventDefault();
            openUpgradeModal(
                btn.getAttribute('data-id'),
                btn.getAttribute('data-username'),
                btn.getAttribute('data-email')
            );
        });

        // 收集权限矩阵
        function collectPermissions() {
            var perms = {};
            var cbs = upgradeOverlay.querySelectorAll('input[name^="upgrade_perm["]:checked');
            cbs.forEach(function(cb) {
                var match = cb.name.match(/^upgrade_perm\[(\w+)\]\[\]$/);
                if (match) {
                    var mod = match[1];
                    if (!perms[mod]) perms[mod] = [];
                    perms[mod].push(cb.value);
                }
            });
            return perms;
        }

        // 确认升级
        upgradeModalConfirm.addEventListener('click', function() {
            if (!pendingUpgradeUserId || upgradeModalConfirm.disabled) return;

            var permissions = collectPermissions();
            var hasAny = false;
            for (var k in permissions) {
                if (permissions.hasOwnProperty(k) && permissions[k].length > 0) {
                    hasAny = true;
                    break;
                }
            }
            if (!hasAny) {
                showMessage('请至少选择一项权限', 'error');
                return;
            }

            upgradeModalConfirm.disabled = true;
            upgradeModalConfirm.textContent = '升级中...';

            fetch('?action=upgrade_to_sub', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    user_id: parseInt(pendingUpgradeUserId),
                    permissions: permissions
                })
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                closeUpgradeModal();
                if (data.success) {
                    showMessage(data.message, 'success');
                    setTimeout(function() { location.reload(); }, 1000);
                } else {
                    showMessage(data.message, 'error');
                }
            })
            .catch(function(err) {
                closeUpgradeModal();
                showMessage('请求失败，请重试', 'error');
            });
        });
    })();
    </script>
    <?php endif; ?>

    <?php renderAdminFooterScripts(); ?>
</body>
</html>

