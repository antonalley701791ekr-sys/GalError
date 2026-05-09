<?php
require_once 'includes/user_auth.php';

$logoutUserId = (int)($_SESSION['user_id'] ?? 0);
if ($logoutUserId > 0) {
    try {
        getDB()->prepare("UPDATE users SET last_activity_at = NULL WHERE id = ?")->execute([$logoutUserId]);
    } catch (Exception $e) {
    }
}

// 保留人机验证状态（退出登录不需要重新验证）
$captchaVerified = $_SESSION['captcha_verified'] ?? false;
$captchaVerifiedAt = $_SESSION['captcha_verified_at'] ?? 0;

clearUserSession();
session_destroy();

// 恢复验证状态到新 session
session_start();
if ($captchaVerified && $captchaVerifiedAt) {
    $_SESSION['captcha_verified'] = true;
    $_SESSION['captcha_verified_at'] = $captchaVerifiedAt;
}

header('Location: /');
exit;
