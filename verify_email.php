<?php
require_once 'includes/user_auth.php';
require_once 'includes/mailer.php';
require_once 'includes/view.php';

$message = '';
$messageType = '';
$token = trim($_GET['token'] ?? '');

if ($token === '') {
    $message = '无效的验证链接';
    $messageType = 'error';
} else {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE verify_token = ? AND verify_token_expires > NOW()");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        $stmt = $pdo->prepare("UPDATE users SET email_verified = 1, verify_token = NULL, verify_token_expires = NULL WHERE id = ?");
        $stmt->execute([$user['id']]);
        $message = '邮箱验证成功！你现在可以登录了。';
        $messageType = 'success';
    } else {
        $stmt = $pdo->prepare("SELECT id, username, email_verified, verify_token_expires FROM users WHERE verify_token = ?");
        $stmt->execute([$token]);
        $expiredUser = $stmt->fetch();
        $message = $expiredUser ? '验证链接已过期，请重新注册或联系管理员。' : '验证链接无效或已过期。';
        $messageType = 'error';
    }
}

ob_start(); renderSiteHead(); $siteHeadHtml = ob_get_clean();

view('front/verify_email.twig', [
    'message' => $message,
    'message_type' => $messageType,
    'site_head_html' => $siteHeadHtml,
]);
