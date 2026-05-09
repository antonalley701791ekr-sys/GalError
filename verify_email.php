<?php
require_once 'includes/user_auth.php';
require_once 'includes/mailer.php';

$message = '';
$messageType = '';
$token = trim($_GET['token'] ?? '');

if (empty($token)) {
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
        // 检查 token 是否存在但已过期
        $stmt = $pdo->prepare("SELECT id, username, email_verified, verify_token_expires FROM users WHERE verify_token = ?");
        $stmt->execute([$token]);
        $expiredUser = $stmt->fetch();

        if ($expiredUser) {
            $message = '验证链接已过期，请重新注册或联系管理员。';
        } else {
            $message = '验证链接无效或已过期。';
        }
        $messageType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>邮箱验证 - <?php echo h(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/user.css?v=<?php echo ASSETS_VER; ?>">
    <?php renderSiteHead(); ?>
</head>
<body class="login-page">
    <div class="container">
        <div class="login-container">
            <h1 class="login-title">邮箱验证</h1>
            <div class="alert-<?php echo $messageType; ?>"><?php echo h($message); ?></div>
            <p class="login-back-link">
                <?php if ($messageType === 'success'): ?>
                    <a href="/login">去登录</a>
                <?php else: ?>
                    <a href="/login">去登录</a> | <a href="/">返回首页</a>
                <?php endif; ?>
            </p>
        </div>
    </div>
    <script src="/assets/js/theme.js"></script>
</body>
</html>
