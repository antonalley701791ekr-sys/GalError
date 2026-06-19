<?php
/**
 * 权限系统核心：登录检查、权限验证、侧边栏渲染
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/site_settings_loader.php';
require_once __DIR__ . '/csrf.php';

/**
 * 检查登录状态，未登录则跳转到登录页
 */
function checkLogin() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true
        || !isset($_SESSION['admin_role'])) {
        session_destroy();
        header('Location: login.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $ok = csrf_validate_request_flexible(['admin_form', 'default']);
        if (!$ok) {
            $logLine = '[' . date('Y-m-d H:i:s') . '] admin csrf failed; uri=' . ($_SERVER['REQUEST_URI'] ?? '')
                . '; action=' . (string)($_POST['action'] ?? $_GET['action'] ?? '')
                . '; has_post_csrf=' . (isset($_POST['_csrf']) ? '1' : '0')
                . '; has_header_csrf=' . (!empty($_SERVER['HTTP_X_CSRF_TOKEN']) ? '1' : '0')
                . '; has_fallback_csrf=' . (!empty($_POST['_csrf_header_token']) ? '1' : '0')
                . '; session_id=' . session_id() . "\n";
            @file_put_contents(BASE_PATH . UPLOAD_PATH . 'csrf_debug.log', $logLine, FILE_APPEND);
            http_response_code(403);
            echo 'CSRF token 校验失败';
            exit;
        }
    }

    updateCurrentAdminActivity(false);
}

/**
 * 更新当前管理员最后活动时间（节流写入）
 */
function updateCurrentAdminActivity($force = false) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        return;
    }

    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    if ($adminId <= 0) {
        return;
    }

    $now = time();
    $lastTouch = (int)($_SESSION['admin_last_activity_touch'] ?? 0);
    if (!$force && ($now - $lastTouch) < 60) {
        return;
    }

    try {
        $stmt = getDB()->prepare("UPDATE users SET last_activity_at = NOW() WHERE id = ?");
        $stmt->execute([$adminId]);
        $_SESSION['admin_last_activity_touch'] = $now;
        if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $adminId) {
            $_SESSION['user_last_activity_touch'] = $now;
        }
    } catch (Exception $e) {
    }
}

/**
 * 判断是否为超级管理员
 */
function isSuperAdmin() {
    return isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super';
}

/**
 * 检查当前管理员是否有指定模块的指定操作权限
 */
function hasPermission($module, $action) {
    if (isSuperAdmin()) {
        return true;
    }
    $permsJson = $_SESSION['admin_permissions'] ?? '';
    if (empty($permsJson)) {
        return false;
    }
    $perms = is_array($permsJson) ? $permsJson : json_decode($permsJson, true);
    if (!is_array($perms) || !isset($perms[$module]) || !is_array($perms[$module])) {
        return false;
    }
    return in_array($action, $perms[$module], true);
}

/**
 * 要求指定权限，无权限则跳转
 */
function requirePermission($module, $action) {
    if (!hasPermission($module, $action)) {
        $_SESSION['admin_msg'] = '您没有权限执行此操作';
        $_SESSION['admin_msg_type'] = 'error';
        header('Location: index.php');
        exit;
    }
}

/**
 * 要求超级管理员身份
 */
function requireSuperAdmin() {
    if (!isSuperAdmin()) {
        $_SESSION['admin_msg'] = '此功能仅超级管理员可访问';
        $_SESSION['admin_msg_type'] = 'error';
        header('Location: index.php');
        exit;
    }
}

/**
 * 权限禁用：返回追加到 class 属性的禁用类名
 * 用法：class="btn<?php echo pd('games','add'); ?>"
 */
function pd($module, $action) {
    return hasPermission($module, $action) ? '' : ' perm-disabled';
}

/**
 * 超级管理员禁用：返回追加到 class 属性的禁用类名
 */
function pdSuper() {
    return isSuperAdmin() ? '' : ' perm-disabled';
}

/**
 * 权限禁用：返回 <a> 标签额外属性（阻止点击、阻止跳转）
 */
function pdAttr($module, $action) {
    return hasPermission($module, $action) ? '' : ' title="无权限" onclick="return false;" tabindex="-1"';
}

/**
 * 超级管理员禁用：返回 <a> 标签额外属性
 */
function pdSuperAttr() {
    return isSuperAdmin() ? '' : ' title="无权限" onclick="return false;" tabindex="-1"';
}

/**
 * 权限禁用：返回 <button> 标签额外属性
 */
function pdBtnAttr($module, $action) {
    return hasPermission($module, $action) ? '' : ' title="无权限" disabled';
}

/**
 * 渲染后台侧边栏
 */
function renderAdminSidebar($currentPage) {
    // 查询待审核资源数量（用于红色徽章）
    $pdo = getDB();
    $hasViewedAt = false;
    try {
        $hasViewedAt = $pdo->query("SHOW COLUMNS FROM errors LIKE 'viewed_at'")->rowCount() > 0
            && $pdo->query("SHOW COLUMNS FROM error_revisions LIKE 'viewed_at'")->rowCount() > 0;
    } catch (Exception $e) {
        $hasViewedAt = false;
    }

    $errorsPendingSql = $hasViewedAt
        ? "(SELECT COUNT(*) FROM errors WHERE status='pending' AND viewed_at IS NULL) as pending_errors"
        : "(SELECT COUNT(*) FROM errors WHERE status='pending') as pending_errors";

    $badgeRow = $pdo->query("SELECT
        (SELECT COUNT(*) FROM games WHERE status='pending') as pending_games,
        (SELECT COUNT(*) FROM articles WHERE status='pending') +
        (SELECT COUNT(*) FROM article_revisions WHERE status='pending') as pending_articles,
        $errorsPendingSql
    ")->fetch();
    $badgeCounts = [
        'games.php'          => (int)$badgeRow['pending_games'],
        'article_review.php' => (int)$badgeRow['pending_articles'],
        'errors.php'         => (int)$badgeRow['pending_errors'],
    ];

    // SVG 图标定义（stroke 风格，viewBox 0 0 24 24）
    $icons = [
        'index.php'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>',
        'games.php'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 12h4"/><path d="M8 10v4"/><circle cx="15" cy="11" r="1"/><circle cx="18" cy="11" r="1"/></svg>',
        'game_review.php'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>',
        'categories.php'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>',
        'errors.php'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        'solutions.php'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3 5h5l-4 4 2 7-6-4-6 4 2-7-4-4h5z"/></svg>',
        'system_maintenance.php' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.39 4.84L20 8l-4 3.9.94 5.51L12 15.98 7.06 17.41 8 11.9 4 8l5.61-1.16L12 2z"/><path d="M12 9v6"/><path d="M9 12h6"/></svg>',
        'article_review.php' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
        'user_manage.php'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'site_settings.php'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/></svg>',
        'sensitive_logs.php' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h18v18H3z"/><path d="M7 7h10"/><path d="M7 12h10"/><path d="M7 17h6"/></svg>',
        'url_whitelist.php' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.07 0l1.41-1.41a5 5 0 0 0-7.07-7.07L10 5"/><path d="M14 11a5 5 0 0 0-7.07 0L5.51 12.41a5 5 0 0 0 7.07 7.07L14 19"/></svg>',
        'documents.php'      => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/><path d="m7 9 3 2-3 2"/><path d="M14 11h3"/></svg>',
        'pages.php'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><path d="M2 10h20"/><path d="M6 14h4"/><path d="M6 18h8"/></svg>',
        'todos.php'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
        'users.php'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        'smtp_settings.php'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>',
        'admin_settings.php' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'migrate_covers.php' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>',
        'media_cleanup.php'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>',
        'health.php'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
        'logout.php'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
    ];

    $menuItems = [
        ['file' => 'index.php', 'label' => '控制台', 'module' => null, 'super_only' => false],
        ['file' => 'games.php', 'label' => '游戏管理', 'module' => 'games', 'super_only' => false],
        ['file' => 'categories.php', 'label' => '报错分类管理', 'module' => 'categories', 'super_only' => false],
        ['file' => 'errors.php', 'label' => '报错管理', 'module' => 'errors', 'super_only' => false],
        ['file' => 'solutions.php', 'label' => '解决方案管理', 'module' => 'errors', 'super_only' => false],
        ['file' => 'article_review.php', 'label' => '文章管理', 'module' => 'articles', 'super_only' => false],
        ['divider' => true],
        ['file' => 'user_manage.php', 'label' => '用户管理', 'module' => 'users', 'super_only' => false],
        ['file' => 'site_settings.php', 'label' => '站点外观', 'module' => 'site', 'super_only' => false],
        ['file' => 'sensitive_logs.php', 'label' => '敏感词日志', 'module' => 'sensitive_logs', 'super_only' => false],
        ['file' => 'url_whitelist.php', 'label' => '链接白名单', 'module' => 'url_whitelist', 'super_only' => false],
        ['file' => 'pages.php', 'label' => '页面管理', 'module' => 'site', 'super_only' => false],
        ['file' => 'documents.php', 'label' => '文档管理', 'module' => 'documents', 'super_only' => false],
        ['file' => 'todos.php', 'label' => '网站待办', 'module' => 'todos', 'super_only' => false],
        ['divider' => true],
        ['file' => 'users.php', 'label' => '管理员设置', 'module' => null, 'super_only' => true],
        ['file' => 'smtp_settings.php', 'label' => '邮箱设置', 'module' => null, 'super_only' => true],
        ['file' => 'admin_settings.php', 'label' => '个人设置', 'module' => null, 'super_only' => false],
        ['file' => 'health.php', 'label' => '系统健康', 'module' => null, 'super_only' => true],
        ['file' => 'media_cleanup.php', 'label' => '图片清理', 'module' => null, 'super_only' => true],
        ['divider' => true],
        ['file' => 'logout.php', 'label' => '退出登录', 'module' => null, 'super_only' => false],
    ];

    echo '<aside class="admin-sidebar">';
    echo '<button type="button" class="admin-menu-toggle" aria-expanded="false" aria-controls="adminMenu" onclick="toggleAdminMobileMenu()">';
    echo '<span>导航菜单</span>';
    echo '<span class="admin-menu-toggle-arrow" aria-hidden="true"></span>';
    echo '</button>';

    echo '<div class="admin-menu-collapse">';
    echo '<ul class="admin-menu" id="adminMenu">';
    // 系统维护子菜单已下线（任务5）：一次性迁移入口改用 CLI `php migrate.php`，详见 includes/migrations/runner.php
    $systemMaintenanceChildren = [];
    $systemMaintenanceOpen = ($currentPage === 'system_maintenance.php');
    foreach ($systemMaintenanceChildren as $child) {
        if ($currentPage === $child['file']) {
            $systemMaintenanceOpen = true;
            break;
        }
    }

    foreach ($menuItems as $item) {
        if (isset($item['divider'])) {
            echo '<li class="admin-menu-divider"></li>';
            continue;
        }
        // 权限过滤：无权限则隐藏
        if ($item['super_only'] && !isSuperAdmin()) {
            continue;
        }
        if ($item['module'] && !hasPermission($item['module'], 'view')) {
            // 游戏管理：有 game_review:view 权限也可看到
            if ($item['file'] === 'games.php' && hasPermission('game_review', 'view')) {
                // 允许访问
            } else {
                continue;
            }
        }
        $active = ($currentPage === $item['file']) ? ' class="active"' : '';
        $icon = $icons[$item['file']] ?? '';
        $badge = '';
        if (isset($badgeCounts[$item['file']]) && $badgeCounts[$item['file']] > 0) {
            $badge = '<span style="background:var(--accent-red);color:var(--bg-primary);border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px;">'
                   . $badgeCounts[$item['file']] . '</span>';
        }

        if ($item['file'] === 'system_maintenance.php') {
            $groupClass = $systemMaintenanceOpen ? 'admin-menu-group open' : 'admin-menu-group';
            $expandedAttr = $systemMaintenanceOpen ? 'true' : 'false';
            echo '<li class="' . $groupClass . '">';
            echo '<button type="button" class="admin-menu-group-toggle" onclick="this.parentNode.classList.toggle(\'open\'); this.setAttribute(\'aria-expanded\', this.parentNode.classList.contains(\'open\') ? \'true\' : \'false\')" aria-expanded="' . $expandedAttr . '">';
            echo '<span class="admin-menu-group-toggle-main">' . $icon . '<span>' . htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') . '</span></span>';
            echo '<span class="admin-menu-group-arrow" aria-hidden="true"></span>';
            echo '</button>';
            echo '<ul class="admin-submenu">';
            foreach ($systemMaintenanceChildren as $child) {
                $childActive = ($currentPage === $child['file']) ? ' class="active"' : '';
                echo '<li><a href="/admin/' . $child['file'] . '"' . $childActive . '><span>' . htmlspecialchars($child['label'], ENT_QUOTES, 'UTF-8') . '</span></a></li>';
            }
            echo '</ul></li>';
            continue;
        }

        echo '<li><a href="/admin/' . $item['file'] . '"' . $active . '>' . $icon . '<span>' . htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') . '</span>' . $badge . '</a></li>';
    }
    echo '</ul>';
    echo '</div>';
    echo '</aside>';
}

/**
 * 渲染后台顶部固定导航栏
 */
function renderAdminTopbar() {
    $avatar = $_SESSION['admin_avatar'] ?? '';
    $username = htmlspecialchars($_SESSION['admin_username'] ?? '', ENT_QUOTES, 'UTF-8');

    echo '<header class="admin-topbar">';
    echo '<div class="admin-topbar-left">';
    echo '<a href="/" class="btn btn-secondary btn-sm">返回前端</a>';
    // 用户头像和用户名
    echo '<div class="admin-topbar-user">';
    if ($avatar && file_exists(BASE_PATH . $avatar)) {
        echo '<img src="/' . htmlspecialchars($avatar, ENT_QUOTES, 'UTF-8') . '" class="admin-avatar-small" alt="">';
    } else {
        echo '<div class="admin-avatar-small avatar-fallback">' . mb_substr($username, 0, 1) . '</div>';
    }
    echo '<span class="sidebar-username">' . $username . '</span>';
    echo '</div>';
    echo '</div>';
    echo '<div class="admin-topbar-right">';
    renderThemeToggle();
    echo '</div>';
    echo '</header>';
}

/**
 * 渲染后台 head 中的 FOUC 防闪脚本
 */
function renderAdminHeadScript() {
    $styleVersion = ASSETS_VER;
    $stylePath = BASE_PATH . '/assets/css/style.css';
    if (file_exists($stylePath)) {
        $styleVersion .= '-' . @filemtime($stylePath);
    }
    echo '<script>(function(){var t;try{t=localStorage.getItem("galerror-theme")}catch(e){}if(!t){t=window.matchMedia&&window.matchMedia("(prefers-color-scheme:light)").matches?"light":"dark"}document.documentElement.setAttribute("data-theme",t)})()</script>' . "\n";
    echo '<script>window.__ADMIN_STYLE_VER=' . json_encode($styleVersion) . ';</script>' . "\n";
    echo '<link rel="stylesheet" href="/assets/css/user.css?v=' . ASSETS_VER . '">' . "\n";
}

/**
 * 渲染后台页脚脚本（theme.js）
 */
function renderAdminFooterScripts() {
    echo '<script src="/assets/js/theme.js"></script>' . "\n";
    echo '<script src="/assets/js/user-dropdown.js?v=' . ASSETS_VER . '"></script>' . "\n";
    echo '<script src="/assets/js/user-online-ping.js?v=' . ASSETS_VER . '"></script>' . "\n";
    echo '<script>(function(){var mq=window.matchMedia("(max-width: 768px)");function getEls(){return{sidebar:document.querySelector(".admin-sidebar"),toggle:document.querySelector(".admin-menu-toggle")}}function collapse(){var els=getEls();if(!els.sidebar||!els.toggle)return;els.sidebar.classList.remove("admin-menu-expanded");els.toggle.setAttribute("aria-expanded",mq.matches?"false":"true")}function sync(){collapse()}window.toggleAdminMobileMenu=function(){var els=getEls();if(!els.sidebar||!els.toggle||!mq.matches)return;var expanded=els.sidebar.classList.toggle("admin-menu-expanded");els.toggle.setAttribute("aria-expanded",expanded?"true":"false")};document.addEventListener("click",function(e){if(!mq.matches)return;var els=getEls();if(!els.sidebar||!els.toggle)return;if(!els.sidebar.classList.contains("admin-menu-expanded"))return;var link=e.target&&e.target.closest?e.target.closest(".admin-menu a"):null;if(link){collapse();return;}if(els.toggle.contains(e.target))return;if(els.sidebar.contains(e.target))return;collapse()});document.addEventListener("keydown",function(e){if(!mq.matches)return;if(e.key==="Escape"){collapse()}});if(mq.addEventListener){mq.addEventListener("change",sync)}else if(mq.addListener){mq.addListener(sync)}sync();})();</script>' . "\n";
}

/**
 * 渲染主题切换按钮 HTML
 */
function renderThemeToggle() {
    echo '<button class="theme-toggle" onclick="toggleTheme()" title="切换主题"></button>';
}
