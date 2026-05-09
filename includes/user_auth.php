<?php
/**
 * 前端用户认证核心
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/site_settings_loader.php';
require_once __DIR__ . '/captcha.php';

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $cookieParams = session_get_cookie_params();
    $sessionLifetime = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path' => $cookieParams['path'] ?: '/',
        'domain' => $cookieParams['domain'] ?: '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * 获取当前请求 IP（优先复用全局方法）
 */
function getCurrentRequestIp() {
    if (function_exists('getClientIP')) {
        return (string)getClientIP();
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * 校验登录会话绑定（网络/IP 变更时失效）
 */
function validateUserSessionBinding() {
    if (!isUserLoggedIn()) {
        return true;
    }

    $sessionIp = (string)($_SESSION['user_login_ip'] ?? '');
    if ($sessionIp === '') {
        return true;
    }

    $currentIp = getCurrentRequestIp();
    if ($currentIp !== '' && !hash_equals($sessionIp, $currentIp)) {
        clearUserSession();
        return false;
    }

    return true;
}

/**
 * 检查用户是否已登录
 */
function isUserLoggedIn() {
    return isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true;
}

/**
 * 要求用户登录，未登录则跳转登录页
 */
function requireUserLogin() {
    if (!isUserLoggedIn() || !validateUserSessionBinding()) {
        $redirect = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: /login' . ($redirect ? '?redirect=' . urlencode($redirect) : ''));
        exit;
    }
}

/**
 * 获取当前登录用户 ID
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * 获取当前登录用户角色
 */
function getCurrentUserRole() {
    return $_SESSION['user_role'] ?? null;
}

/**
 * 判断当前用户是否为管理员（sub 或 super）
 */
function isAdmin() {
    $role = getCurrentUserRole();
    return $role === 'sub' || $role === 'super';
}

/**
 * 当前用户发布/修改内容时是否可免审核
 */
function canCurrentUserBypassModeration() {
    return isAdmin();
}

/**
 * 获取当前用户内容提交后的状态
 */
function getCurrentUserModerationStatus() {
    return canCurrentUserBypassModeration() ? 'approved' : 'pending';
}

/**
 * 更新当前登录用户最后活动时间（节流写入）
 */
function updateCurrentUserActivity($force = false) {
    if (!isUserLoggedIn()) {
        return;
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    $now = time();
    $lastTouch = (int)($_SESSION['user_last_activity_touch'] ?? 0);
    if (!$force && ($now - $lastTouch) < 60) {
        return;
    }

    try {
        $stmt = getDB()->prepare("UPDATE users SET last_activity_at = NOW() WHERE id = ?");
        $stmt->execute([$userId]);
        $_SESSION['user_last_activity_touch'] = $now;
        if (isset($_SESSION['admin_id']) && (int)$_SESSION['admin_id'] === $userId) {
            $_SESSION['admin_last_activity_touch'] = $now;
        }
    } catch (Exception $e) {
    }
}

/**
 * 设置用户 session，并在管理员角色时同步设置 admin session
 */
function setUserSession($userRow) {
    $_SESSION['user_logged_in'] = true;
    $_SESSION['user_id'] = (int)$userRow['id'];
    $_SESSION['user_username'] = $userRow['username'];
    $_SESSION['user_role'] = $userRow['role'];
    $_SESSION['user_avatar'] = $userRow['avatar'] ?? '';
    $_SESSION['user_email'] = $userRow['email'] ?? '';
    $_SESSION['user_banned'] = !empty($userRow['banned']);

    $now = time();
    $_SESSION['user_last_activity_touch'] = $now;
    $_SESSION['user_login_ip'] = getCurrentRequestIp();

    // 管理员角色同步设置 admin session（后台兼容）
    if ($userRow['role'] === 'sub' || $userRow['role'] === 'super') {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = (int)$userRow['id'];
        $_SESSION['admin_username'] = $userRow['username'];
        $_SESSION['admin_role'] = $userRow['role'];
        $_SESSION['admin_avatar'] = $userRow['avatar'] ?? '';
        $_SESSION['admin_permissions'] = $userRow['permissions'] ?? '';
        $_SESSION['admin_last_activity_touch'] = $now;
    }

    updateCurrentUserActivity(true);
}

/**
 * 清除所有 session 变量
 */
function clearUserSession() {
    $keys = [
        'user_logged_in', 'user_id', 'user_username', 'user_role', 'user_avatar', 'user_email', 'user_banned',
        'user_last_activity_touch',
        'user_login_ip',
        'admin_logged_in', 'admin_id', 'admin_username', 'admin_role', 'admin_avatar', 'admin_permissions',
        'admin_last_activity_touch',
        'admin_msg', 'admin_msg_type'
    ];
    foreach ($keys as $key) {
        unset($_SESSION[$key]);
    }
}

/**
 * 获取用户头像 URL（带 fallback）
 */
function getUserAvatarUrl() {
    $avatar = $_SESSION['user_avatar'] ?? '';
    if ($avatar && file_exists(BASE_PATH . $avatar)) {
        return '/' . $avatar;
    }
    return '';
}

/**
 * 获取用户名首字（用于无头像时显示）
 */
function getUserInitial() {
    $username = $_SESSION['user_username'] ?? '?';
    return mb_substr($username, 0, 1);
}

/**
 * 获取角色显示名
 */
function getRoleLabel($role) {
    $labels = [
        'user' => '用户',
        'sub' => '管理员',
        'super' => '超级管理员'
    ];
    return $labels[$role] ?? '用户';
}

/**
 * 检查当前用户是否被封禁
 */
function isUserBanned() {
    return !empty($_SESSION['user_banned']);
}

/**
 * 要求用户未被封禁，被封禁则显示提示
 * 返回 true 表示被封禁，调用方应停止后续操作
 */
function checkUserBanned() {
    return isUserBanned();
}

// 登录用户在请求期间自动续期活动时间
if (isUserLoggedIn() && !validateUserSessionBinding()) {
    // 会话绑定失效，交由业务页面的登录检查流程处理
} else {
    updateCurrentUserActivity(false);
}

