<?php
/**
 * 前端用户认证核心
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/site_settings_loader.php';
require_once __DIR__ . '/captcha.php';
require_once __DIR__ . '/csrf.php';

/**
 * 判断当前请求是否为 HTTPS（兼容反向代理 / CDN）
 */
function isHttpsRequest() {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    if (!empty($_SERVER['REQUEST_SCHEME']) && strtolower((string)$_SERVER['REQUEST_SCHEME']) === 'https') {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $forwardedProto = strtolower(trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0]));
        if ($forwardedProto === 'https') {
            return true;
        }
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
        return true;
    }

    if (!empty($_SERVER['HTTP_CF_VISITOR'])) {
        $cfVisitor = json_decode((string)$_SERVER['HTTP_CF_VISITOR'], true);
        if (is_array($cfVisitor) && strtolower((string)($cfVisitor['scheme'] ?? '')) === 'https') {
            return true;
        }
    }

    return false;
}

if (!function_exists('getAuthCookieDomain')) {
    function getAuthCookieDomain() {
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $baseDomain = 'galerror.top';
        if ($host === $baseDomain || str_ends_with($host, '.' . $baseDomain)) {
            return '.' . $baseDomain;
        }
        return '';
    }
}

if (session_status() === PHP_SESSION_NONE) {
    $cookieParams = session_get_cookie_params();
    $sessionLifetime = 60 * 60 * 24 * 30;
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    ini_set('session.cookie_lifetime', (string)$sessionLifetime);
    ini_set('session.cookie_path', $cookieParams['path'] ?: '/');
    $cookieDomain = getAuthCookieDomain();
    if ($cookieDomain !== '') {
        ini_set('session.cookie_domain', $cookieDomain);
    }
    ini_set('session.cookie_secure', isHttpsRequest() ? '1' : '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path' => $cookieParams['path'] ?: '/',
        'domain' => $cookieDomain,
        'secure' => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

const REMEMBER_ME_COOKIE = 'remember_me';
const REMEMBER_ME_DAYS = 30;

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
 * 校验登录会话绑定。
 *
 * 普通前台用户不再做 IP 强绑定，避免因移动网络、宽带重拨、代理/CDN
 * 等常见网络变化导致会话被误判失效。
 */
function validateUserSessionBinding() {
    return true;
}

/**
 * 设置/清理持久登录 Cookie
 */
function setRememberMeCookie($value, $expiresAt) {
    setcookie(REMEMBER_ME_COOKIE, $value, [
        'expires' => $expiresAt,
        'path' => '/',
        'domain' => getAuthCookieDomain(),
        'secure' => isHttpsRequest(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function refreshRememberMeCookie($expiresAt = null) {
    $cookie = trim((string)($_COOKIE[REMEMBER_ME_COOKIE] ?? ''));
    if ($cookie === '') {
        return;
    }
    if ($expiresAt === null) {
        $expiresAt = time() + (REMEMBER_ME_DAYS * 24 * 60 * 60);
    }
    setRememberMeCookie($cookie, (int)$expiresAt);
}

function clearRememberMeCookie() {
    unset($_COOKIE[REMEMBER_ME_COOKIE]);
    setRememberMeCookie('', time() - 3600);
}

function revokeRememberMeTokenBySelector($selector) {
    $selector = trim((string)$selector);
    if ($selector === '') {
        return;
    }

    try {
        $stmt = getDB()->prepare("UPDATE remember_tokens SET revoked_at = NOW() WHERE selector = ? AND revoked_at IS NULL");
        $stmt->execute([$selector]);
    } catch (Exception $e) {
    }
}

function purgeExpiredRememberMeTokens() {
    try {
        getDB()->prepare("DELETE FROM remember_tokens WHERE expires_at < NOW() OR revoked_at IS NOT NULL")->execute();
    } catch (Exception $e) {
    }
}

function revokeRememberMeTokensByUserId($userId) {
    $userId = (int)$userId;
    if ($userId <= 0) {
        return;
    }

    try {
        $stmt = getDB()->prepare("UPDATE remember_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
        $stmt->execute([$userId]);
    } catch (Exception $e) {
    }
}

function issueRememberMeToken($userRow) {
    $userId = (int)($userRow['id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $validator);
    $expiresAtTs = time() + (REMEMBER_ME_DAYS * 24 * 60 * 60);
    $expiresAt = date('Y-m-d H:i:s', $expiresAtTs);
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $ipAddress = substr(getCurrentRequestIp(), 0, 45);

    try {
        $pdo = getDB();
        // 先做一次轻量清理，避免 remember_tokens 越积越多。
        purgeExpiredRememberMeTokens();
        $stmt = $pdo->prepare("INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at, last_used_at, user_agent, ip_address) VALUES (?, ?, ?, ?, NOW(), ?, ?)");
        $stmt->execute([$userId, $selector, $tokenHash, $expiresAt, $userAgent, $ipAddress]);
        setRememberMeCookie($selector . ':' . $validator, $expiresAtTs);
    } catch (Exception $e) {
        clearRememberMeCookie();
    }
}

function consumeRememberMeLogin() {
    if (isUserLoggedIn()) {
        return true;
    }

    $cookie = trim((string)($_COOKIE[REMEMBER_ME_COOKIE] ?? ''));
    if ($cookie === '' || strpos($cookie, ':') === false) {
        return false;
    }

    [$selector, $validator] = explode(':', $cookie, 2);
    $selector = trim($selector);
    $validator = trim($validator);

    if ($selector === '' || $validator === '') {
        clearRememberMeCookie();
        return false;
    }

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT rt.id, rt.user_id, rt.token_hash, rt.expires_at, u.*
            FROM remember_tokens rt
            INNER JOIN users u ON u.id = rt.user_id
            WHERE rt.selector = ? AND rt.revoked_at IS NULL
            LIMIT 1");
        $stmt->execute([$selector]);
        $row = $stmt->fetch();

        if (!$row) {
            clearRememberMeCookie();
            return false;
        }

        if (strtotime((string)$row['expires_at']) <= time()) {
            revokeRememberMeTokenBySelector($selector);
            clearRememberMeCookie();
            return false;
        }

        $validatorHash = hash('sha256', $validator);
        if (!hash_equals((string)$row['token_hash'], $validatorHash)) {
            revokeRememberMeTokenBySelector($selector);
            clearRememberMeCookie();
            return false;
        }

        if (empty($row['enabled']) || !empty($row['banned']) || empty($row['email_verified'])) {
            revokeRememberMeTokenBySelector($selector);
            clearRememberMeCookie();
            return false;
        }

        // 关键修复：不要在每次自动续登时重新签发新 remember token。
        // 否则旧 cookie 会立即失效，很多场景下会表现为“刚记住登录，过一会儿又掉线”。
        // 只更新当前 token 的 last_used_at，并刷新 session 即可。
        $touchStmt = $pdo->prepare("UPDATE remember_tokens SET last_used_at = NOW() WHERE id = ? AND revoked_at IS NULL");
        $touchStmt->execute([(int)$row['id']]);

        refreshRememberMeCookie(strtotime((string)$row['expires_at']));
        session_regenerate_id(true);
        setUserSession($row);
        return true;
    } catch (Exception $e) {
        clearRememberMeCookie();
        return false;
    }
}

/**
 * 检查用户是否已登录
 */
function isUserLoggedIn() {
    if (isset($_SESSION['user_logged_in']) && $_SESSION['user_logged_in'] === true) {
        return true;
    }

    // 允许任意页面在读取登录态时，先尝试用“记住我”令牌恢复会话。
    // 这样即使 PHP session 因长时间未访问而失效，用户重新打开网站时仍可自动保持登录。
    static $attemptedRestore = false;
    if (!$attemptedRestore) {
        $attemptedRestore = true;
        if (consumeRememberMeLogin()) {
            return true;
        }
    }

    return false;
}

/**
 * 要求用户登录，未登录则跳转登录页
 */
function requireUserLogin() {
    if (!isUserLoggedIn()) {
        consumeRememberMeLogin();
    }

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

function getUserCardDataById($userId) {
    $userId = (int)$userId;
    if ($userId <= 0) {
        return null;
    }

    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id, username, role, avatar FROM users WHERE id = ? AND enabled = 1 LIMIT 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            return null;
        }

        $approvedFilter = " AND status = 'approved'";

        $stats = [
            'games_count' => 0,
            'errors_count' => 0,
            'solutions_count' => 0,
            'solution_fix_count' => 0,
            'articles_count' => 0,
            'discussions_count' => 0,
            'comments_count' => 0,
        ];

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM games WHERE user_id = ?" . $approvedFilter);
        $stmt->execute([$userId]);
        $stats['games_count'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM errors WHERE user_id = ?" . $approvedFilter);
        $stmt->execute([$userId]);
        $stats['errors_count'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM error_solutions WHERE user_id = ?" . ($user['role'] === 'user' ? " AND status = 'approved'" : ''));
        $stmt->execute([$userId]);
        $stats['solutions_count'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM articles WHERE user_id = ?" . $approvedFilter);
        $stmt->execute([$userId]);
        $stats['articles_count'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM error_revisions WHERE user_id = ? AND status = 'approved'");
        $stmt->execute([$userId]);
        $stats['solution_fix_count'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM discussions WHERE user_id = ? AND status = 'active'");
        $stmt->execute([$userId]);
        $stats['discussions_count'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE user_id = ? AND status = 'active'");
        $stmt->execute([$userId]);
        $stats['comments_count'] = (int)$stmt->fetchColumn();

        return [
            'id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'role' => (string)$user['role'],
            'role_label' => getRoleLabel($user['role']),
            'avatar' => (!empty($user['avatar']) && file_exists(BASE_PATH . $user['avatar'])) ? '/' . $user['avatar'] : '',
            'can_message' => isUserLoggedIn() && (int)getCurrentUserId() !== (int)$user['id'],
            'profile_url' => '/profile?user_id=' . (int)$user['id'],
            'message_url' => urlChat((int)$user['id']),
            'stats' => $stats,
        ];
    } catch (Exception $e) {
        return null;
    }
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

// 尝试通过持久登录令牌自动恢复会话
// 登录用户在请求期间自动续期活动时间
if (isUserLoggedIn() && !validateUserSessionBinding()) {
    // 会话绑定失效，交由业务页面的登录检查流程处理
} else {
    updateCurrentUserActivity(false);
}

