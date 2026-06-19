<?php
require_once 'includes/user_auth.php';
require_once 'includes/mailer.php';
require_once 'includes/view.php';

// 已登录则跳转
if (isUserLoggedIn()) {
    header('Location: /');
    exit;
}

$error = '';
$redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? '';

// 处理重发验证邮件
if (isset($_POST['resend_verify']) && !empty($_POST['resend_email'])) {
    if (!csrf_validate_request('login_resend')) {
        $error = '请求已过期，请刷新后重试';
    } else {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE email = ? AND email_verified = 0");
    $stmt->execute([trim($_POST['resend_email'])]);
    $user = $stmt->fetch();
    if ($user) {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $pdo->prepare("UPDATE users SET verify_token = ?, verify_token_expires = ? WHERE id = ?")->execute([$token, $expires, $user['id']]);
        sendVerificationEmail($user['email'], $user['username'], $token);
        $error = '验证邮件已重新发送，请查收';
    } else {
        $error = '未找到需要验证的账户';
    }
    }
}

$showResend = false;
$resendEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['resend_verify'])) {
    if (!csrf_validate_request('login_form')) {
        $error = '请求已过期，请刷新后重试';
    } else {
    $loginInput = trim($_POST['login_input'] ?? '');
    $password = $_POST['password'] ?? '';
    $rememberMe = !empty($_POST['remember_me']);

    if (needsCaptchaVerificationForAuth()) {
        $error = '请先完成人机验证';
    } elseif (empty($loginInput) || empty($password)) {
        $error = '请输入用户名/邮箱和密码';
    } else {
        $pdo = getDB();
        // 先以用户名查，再以邮箱查
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$loginInput]);
        $user = $stmt->fetch();

        if (!$user) {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$loginInput]);
            $user = $stmt->fetch();
        }

        if ($user && password_verify($password, $user['password'])) {
            if (!$user['enabled']) {
                $error = '该账户已被禁用';
            } elseif (!$user['email_verified']) {
                $error = '邮箱尚未验证，请先验证邮箱';
                $showResend = true;
                $resendEmail = $user['email'];
            } else {
                session_regenerate_id(true);
                setUserSession($user);
                if ($rememberMe) {
                    issueRememberMeToken($user);
                } else {
                    clearRememberMeCookie();
                }
                $_SESSION['captcha_verified'] = true;
                $_SESSION['captcha_verified_at'] = time();
                if (!empty($user['banned'])) {
                    $_SESSION['user_banned'] = true;
                }

                // 首次登录：发送入站须知站内信（仅一次）
                $welcomeCheck = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE user_id = ? AND title = '欢迎加入！请阅读入站须知'");
                $welcomeCheck->execute([$user['id']]);
                if ($welcomeCheck->fetchColumn() == 0) {
                    sendNotification($user['id'], '欢迎加入！请阅读入站须知', '欢迎来到 GalError！请花几分钟阅读入站须知，了解社区规范和使用指南。', '/page/entry-guide');
                }

                $target = '/';
                if ($redirect && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//')) {
                    $target = $redirect;
                }
                header('Location: ' . $target);
                exit;
            }
        } else {
            $error = '用户名/邮箱或密码错误';
        }
    }
    }
}
ob_start();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - <?php echo h(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/user.css?v=<?php echo ASSETS_VER; ?>">
    <?php renderSiteHead(); ?>
</head>
<body class="login-page">
    <div class="container">
        <div class="login-container">
            <h1 class="login-title">用户登录</h1>

            <?php if ($error): ?>
                <div class="alert-error"><?php echo h($error); ?></div>
            <?php endif; ?>

            <?php if ($showResend): ?>
            <form method="post" id="resend-verify-form" style="margin-bottom: 16px;">
                <?php echo csrf_input('login_resend'); ?>
                <input type="hidden" name="resend_email" value="<?php echo h($resendEmail); ?>">
                <button type="submit" name="resend_verify" value="1" class="btn btn-secondary btn-full">重新发送验证邮件</button>
            </form>
            <?php endif; ?>

            <form method="post" id="login-form">
                <?php echo csrf_input('login_form'); ?>
                <input type="hidden" name="redirect" value="<?php echo h($redirect); ?>">
                <div class="form-group">
                    <label class="form-label">用户名 / 邮箱</label>
                    <input type="text" name="login_input" class="form-input" required autofocus value="<?php echo h($_POST['login_input'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">密码</label>
                    <input type="password" name="password" class="form-input" required>
                </div>

                <div class="form-group">
                    <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-weight: normal; color: var(--text-secondary);">
                        <input type="checkbox" name="remember_me" value="1" <?php echo !empty($_POST['remember_me']) ? 'checked' : ''; ?>>
                        <span>记住我（30 天内自动续登）</span>
                    </label>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-full">登录</button>
                </div>
            </form>

            <p class="login-back-link">
                还没有账号？<a href="/register">去注册</a> | <a href="/">返回首页</a>
            </p>
        </div>
    </div>
    <script src="/assets/js/theme.js"></script>
    <?php if (!isCaptchaVerified()): ?>
    <script>window.__captchaApiUrl = '/api/captcha.php';</script>
    <script src="/assets/js/captcha.js?v=<?php echo ASSETS_VER; ?>"></script>
    <script>
    (function() {
        var form = document.getElementById('login-form');
        if (!form) return;

        var submittingAfterCaptcha = false;

        form.addEventListener('submit', function(e) {
            if (submittingAfterCaptcha) {
                return;
            }

            e.preventDefault();
            var captcha = new window.SliderCaptcha();
            captcha.onSuccess = function() {
                submittingAfterCaptcha = true;
                form.submit();
            };
            captcha.show();
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>
<?php
$pageHtml = ob_get_clean();
view('front/login.twig', ['page_html' => $pageHtml]);

