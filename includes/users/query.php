<?php
function usersGetContext(PDO $pdo): array {
    $permModules = [
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
    $actionLabels = ['view' => '查看', 'add' => '添加', 'edit' => '编辑', 'delete' => '删除'];
    $onlineTimeoutMinutes = defined('ONLINE_TIMEOUT_MINUTES') ? max(1, (int)ONLINE_TIMEOUT_MINUTES) : 10;
    $onlineTimeoutSeconds = $onlineTimeoutMinutes * 60;
    $admins = $pdo->query("SELECT id, username, role, avatar, permissions, enabled, username_changes, email, email_verified, created_at, last_activity_at FROM users WHERE role IN ('sub','super') ORDER BY id ASC")->fetchAll();
    foreach ($admins as &$admin) {
        $admin['status_html'] = !empty($admin['last_activity_at']) && strtotime((string)$admin['last_activity_at']) >= (time() - $onlineTimeoutSeconds)
            ? '<span class="status status-enabled">在线</span>'
            : '<span class="status status-disabled">离线</span>';
    }
    unset($admin);
    return compact('permModules', 'actionLabels', 'admins');
}
