<?php
require_once '../includes/config.php';

session_start();

$logoutAdminId = (int)($_SESSION['admin_id'] ?? 0);
if ($logoutAdminId > 0) {
    try {
        getDB()->prepare("UPDATE users SET last_activity_at = NULL WHERE id = ?")->execute([$logoutAdminId]);
    } catch (Exception $e) {
    }
}

// 保留人机验证状态
$captchaVerified = $_SESSION['captcha_verified'] ?? false;
$captchaVerifiedAt = $_SESSION['captcha_verified_at'] ?? 0;

// 销毁所有会话数据
session_destroy();

// 恢复验证状态到新 session
session_start();
if ($captchaVerified && $captchaVerifiedAt) {
    $_SESSION['captcha_verified'] = true;
    $_SESSION['captcha_verified_at'] = $captchaVerifiedAt;
}

// 重定向到登录页面
header('Location: login.php');
exit;
?>