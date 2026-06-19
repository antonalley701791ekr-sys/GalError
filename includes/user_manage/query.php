<?php
function userManagePermissionModules(): array {
    return [
        'games' => ['label' => '游戏管理', 'actions' => ['view', 'add', 'edit', 'delete']],
        'game_review' => ['label' => '游戏审核', 'actions' => ['view', 'edit', 'delete']],
        'categories' => ['label' => '报错分类管理', 'actions' => ['view', 'add', 'edit', 'delete']],
        'errors' => ['label' => '报错管理', 'actions' => ['view', 'edit', 'delete']],
        'articles' => ['label' => '文章管理', 'actions' => ['view', 'edit', 'delete']],
        'users' => ['label' => '用户管理', 'actions' => ['view', 'edit']],
        'site' => ['label' => '站点外观', 'actions' => ['view', 'edit']],
        'sensitive_logs' => ['label' => '敏感词日志查看', 'actions' => ['view', 'add', 'edit', 'delete']],
        'url_whitelist' => ['label' => 'URL 白名单管理', 'actions' => ['view', 'edit']],
        'documents' => ['label' => '文档管理', 'actions' => ['view', 'add', 'edit', 'delete']],
        'todos' => ['label' => '网站待办', 'actions' => ['view', 'add', 'edit', 'delete']],
    ];
}

function loadUserManageQueryContext(PDO $pdo, array $filters): array {
    $isSuperAdmin = isSuperAdmin();
    $permModules = userManagePermissionModules();
    $actionLabels = ['view' => '查看', 'add' => '添加', 'edit' => '编辑', 'delete' => '删除'];
    $onlineTimeoutMinutes = defined('ONLINE_TIMEOUT_MINUTES') ? max(1, (int)ONLINE_TIMEOUT_MINUTES) : 10;
    $onlineTimeoutSeconds = $onlineTimeoutMinutes * 60;

    $searchType = $filters['search_type'] ?? '';
    $searchKeyword = trim($filters['keyword'] ?? '');
    $roleFilter = $filters['role_filter'] ?? '';
    $page = max(1, intval($filters['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    $where = [];
    $params = [];
    if ($roleFilter === 'user') $where[] = "role = 'user'";
    elseif ($roleFilter === 'sub') $where[] = "role = 'sub'";
    elseif ($roleFilter === 'super') $where[] = "role = 'super'";
    elseif ($roleFilter === 'admin') $where[] = "role IN ('sub','super')";
    if ($searchKeyword !== '') {
        if ($searchType === 'id') { $where[] = "id = ?"; $params[] = intval($searchKeyword); }
        elseif ($searchType === 'username_exact') { $where[] = "username = ?"; $params[] = $searchKeyword; }
        else { $where[] = "username LIKE ?"; $params[] = '%' . $searchKeyword . '%'; }
    }
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) as c FROM users $whereClause");
    $countStmt->execute($params);
    $total = (int)($countStmt->fetch()['c'] ?? 0);
    $pagination = paginate($total, $page, $perPage);

    $stmt = $pdo->prepare("SELECT id, username, email, role, avatar, enabled, banned, email_verified, permissions, created_at, last_activity_at FROM users $whereClause ORDER BY id DESC LIMIT $offset, $perPage");
    $stmt->execute($params);
    $userList = $stmt->fetchAll();
    foreach ($userList as &$u) {
        $role = (string)($u['role'] ?? '');
        if ($role === 'super') {
            $u['role_label'] = '超级管理员';
        } elseif ($role === 'sub') {
            $u['role_label'] = '子管理员';
        } elseif ($role === 'user') {
            $u['role_label'] = '普通用户';
        } else {
            $u['role_label'] = '未知';
        }
        $email = trim((string)($u['email'] ?? ''));
        $u['masked_email'] = $email !== '' ? preg_replace('/(^.).*(@.*$)/u', '$1***$2', $email) : '未设置';
        $u['email_status'] = !empty($u['email_verified']) ? '<span class="status status-enabled">已验证</span>' : '<span class="status status-pending">未验证</span>';
        $isOnline = !empty($u['last_activity_at']) && strtotime((string)$u['last_activity_at']) >= (time() - $onlineTimeoutSeconds);
        $u['status_html'] = $isOnline ? '<span class="status status-enabled">在线</span>' : '<span class="status status-disabled">离线</span>';
        $u['created_at_formatted'] = !empty($u['created_at']) ? date('Y-m-d H:i', strtotime((string)$u['created_at'])) : '-';
        $u['is_selectable'] = !empty($u['enabled']);
        $buttons = [];
        if (in_array((string)($u['role'] ?? ''), ['user', 'sub'], true)) {
            $buttons[] = '<button type="button" class="btn btn-sm btn-secondary" data-action="reset_password" data-id="' . (int)$u['id'] . '" data-user-id="' . (int)$u['id'] . '" data-username="' . htmlspecialchars((string)$u['username'], ENT_QUOTES, 'UTF-8') . '">重置密码</button>';
            $buttons[] = !empty($u['email_verified'])
                ? '<span class="btn btn-sm btn-secondary" style="pointer-events:none;opacity:.6;">已验证</span>'
                : '<button type="button" class="btn btn-sm btn-verify" data-action="' . ((string)($u['role'] ?? '') === 'sub' ? 'verify_admin_email' : 'verify_email') . '" data-id="' . (int)$u['id'] . '" data-user-id="' . (int)$u['id'] . '">验证邮箱</button>';
            $buttons[] = !empty($u['banned'])
                ? '<button type="button" class="btn btn-sm btn-secondary btn-unban-user" data-action="unban_user" data-id="' . (int)$u['id'] . '" data-user-id="' . (int)$u['id'] . '" data-username="' . htmlspecialchars((string)$u['username'], ENT_QUOTES, 'UTF-8') . '">解封</button>'
                : '<button type="button" class="btn btn-sm btn-danger btn-ban-user" data-action="ban_user" data-id="' . (int)$u['id'] . '" data-user-id="' . (int)$u['id'] . '" data-username="' . htmlspecialchars((string)$u['username'], ENT_QUOTES, 'UTF-8') . '">封禁</button>';
        }
        if ((string)($u['role'] ?? '') === 'sub') {
            $buttons[] = '<button type="button" class="btn btn-sm btn-danger btn-revoke-admin" data-action="revoke_admin" data-id="' . (int)$u['id'] . '" data-user-id="' . (int)$u['id'] . '" data-username="' . htmlspecialchars((string)$u['username'], ENT_QUOTES, 'UTF-8') . '">撤销管理员权限</button>';
        }
        if ((string)($u['role'] ?? '') === 'user' && isSuperAdmin()) {
            $buttons[] = '<button type="button" class="btn btn-sm btn-promote-user" data-action="upgrade_to_sub" data-id="' . (int)$u['id'] . '" data-user-id="' . (int)$u['id'] . '" data-username="' . htmlspecialchars((string)$u['username'], ENT_QUOTES, 'UTF-8') . '" data-email="' . htmlspecialchars((string)$email, ENT_QUOTES, 'UTF-8') . '">升级子管理员</button>';
        }
        $u['actions_html'] = implode(' ', $buttons);
    }
    unset($u);

    $totalAllUsers = (int)$pdo->query("SELECT COUNT(*) as c FROM users")->fetch()['c'];
    $totalUsers = (int)$pdo->query("SELECT COUNT(*) as c FROM users WHERE role = 'user'")->fetch()['c'];
    $totalAdmins = (int)$pdo->query("SELECT COUNT(*) as c FROM users WHERE role IN ('sub','super')")->fetch()['c'];
    $bannedUsers = (int)$pdo->query("SELECT COUNT(*) as c FROM users WHERE role = 'user' AND banned = 1")->fetch()['c'];
    $activeUsers = (int)$pdo->query("SELECT COUNT(*) as c FROM users WHERE role = 'user' AND banned = 0 AND email_verified = 1")->fetch()['c'];
    $unverifiedUsers = (int)$pdo->query("SELECT COUNT(*) as c FROM users WHERE role = 'user' AND email_verified = 0")->fetch()['c'];
    $onlineStmt = $pdo->prepare("SELECT COUNT(*) as c FROM users WHERE enabled = 1 AND last_activity_at IS NOT NULL AND last_activity_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)");
    $onlineStmt->execute([$onlineTimeoutMinutes]);
    $onlineUsersCount = (int)($onlineStmt->fetch()['c'] ?? 0);

    $searchParams = '';
    if ($searchKeyword !== '') $searchParams .= '&search_type=' . urlencode($searchType) . '&keyword=' . urlencode($searchKeyword);
    if ($roleFilter !== '') $searchParams .= '&role_filter=' . urlencode($roleFilter);

    return compact('isSuperAdmin','permModules','actionLabels','onlineTimeoutMinutes','onlineTimeoutSeconds','searchType','searchKeyword','roleFilter','page','perPage','offset','total','pagination','userList','totalAllUsers','totalUsers','totalAdmins','bannedUsers','activeUsers','unverifiedUsers','onlineUsersCount','searchParams');
}
