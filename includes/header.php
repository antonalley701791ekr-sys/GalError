<?php
if (!function_exists('isUserLoggedIn')) {
    require_once __DIR__ . '/user_auth.php';
}

$unreadMsgCount = 0;
$unreadPmCount = 0;
if (isUserLoggedIn()) {
    try {
        $msgStmt = getDB()->prepare('SELECT COUNT(*) FROM messages WHERE user_id = ? AND is_read = 0');
        $msgStmt->execute([getCurrentUserId()]);
        $unreadMsgCount = (int)$msgStmt->fetchColumn();

        $pmStmt = getDB()->prepare('SELECT COUNT(*) FROM private_messages WHERE receiver_id = ? AND is_read = 0');
        $pmStmt->execute([getCurrentUserId()]);
        $unreadPmCount = (int)$pmStmt->fetchColumn();
    } catch (Exception $e) {
        $unreadMsgCount = 0;
        $unreadPmCount = 0;
    }
}

$csrfToken = csrf_token('default');
$apiCodeMessageMap = [
    'method_not_allowed' => '请求方式不正确，请刷新页面后重试',
    'bad_origin' => '请求来源异常，请在本站页面内重试',
    'csrf_failed' => '请求已过期，请刷新页面后重试',
    'unauthorized' => '请先登录后再操作',
    'forbidden' => '您没有权限执行此操作',
    'invalid_params' => '提交参数有误，请检查后重试',
    'invalid_id' => '目标内容不存在或已失效',
    'invalid_fingerprint' => '请求标识无效，请重试',
    'rate_limited' => '操作过于频繁，请稍后再试',
    'db_error' => '服务器繁忙，请稍后重试',
    'unknown_action' => '未知操作请求，请刷新后重试',
    'captcha_rate_limited' => '验证码请求过于频繁，请稍后再试',
    'captcha_verify_failed' => '验证码校验失败，请重新验证',
];

$submitArticleUrl = isUserLoggedIn() ? '/submit_article' : '/login?redirect=' . urlencode('/submit_article');
$submitDiscussionUrl = isUserLoggedIn() ? '/submit_discussion' : '/login?redirect=' . urlencode('/submit_discussion');
$siteLogo = getSiteSetting('site_logo', '');
$avatarUrl = isUserLoggedIn() ? getUserAvatarUrl() : '';
$userInitial = isUserLoggedIn() ? getUserInitial() : '';
$username = isUserLoggedIn() ? (string)($_SESSION['user_username'] ?? '') : '';
$userRole = isUserLoggedIn() ? (string)($_SESSION['user_role'] ?? '') : '';
$roleLabel = isUserLoggedIn() ? getRoleLabel($userRole) : '';
$footerText = getSiteSetting('footer_text', '');
$footerScriptsHtml = '';
if (function_exists('renderSiteFooterScripts')) {
    ob_start();
    renderSiteFooterScripts();
    $footerScriptsHtml = ob_get_clean();
}

echo renderTwig('partials/header.twig', [
    'csrf_token_json' => json_encode($csrfToken, JSON_UNESCAPED_UNICODE),
    'api_code_message_map_json' => json_encode($apiCodeMessageMap, JSON_UNESCAPED_UNICODE),
    'site_logo' => $siteLogo,
    'site_name' => getSiteSetting('site_name', SITE_NAME),
    'submit_article_url' => $submitArticleUrl,
    'submit_discussion_url' => $submitDiscussionUrl,
    'is_logged_in' => isUserLoggedIn(),
    'has_unread' => ($unreadMsgCount + $unreadPmCount) > 0,
    'unread_msg_count' => $unreadMsgCount,
    'unread_pm_count' => $unreadPmCount,
    'avatar_url' => $avatarUrl,
    'user_initial' => $userInitial,
    'username' => $username,
    'user_role' => $userRole,
    'role_label' => $roleLabel,
    'is_admin' => isAdmin(),
    'site_footer_scripts_html' => $footerScriptsHtml,
    'footer_text' => $footerText,
    'assets_ver' => ASSETS_VER,
    'user_card_js_ver' => ASSETS_VER . '-' . (@filemtime(BASE_PATH . 'assets/js/user-card.js') ?: time()),
]);
