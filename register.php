<?php
require_once 'includes/user_auth.php';
require_once 'includes/mailer.php';
require_once 'includes/view.php';

// 已登录则跳转首页
if (isUserLoggedIn()) {
    header('Location: /');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate_request('register_form')) {
        $error = '请求已过期，请刷新后重试';
    } else {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (needsCaptchaVerificationForAuth()) {
        $error = '请先完成人机验证';
    } elseif (empty($username) || empty($email) || empty($password) || empty($confirmPassword)) {
        $error = '请填写所有字段';
    } elseif (!preg_match('/^[\w\x{4e00}-\x{9fff}]{2,30}$/u', $username)) {
        $error = '用户名需 2-30 个字符，仅支持中文、英文、数字和下划线';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '邮箱格式不正确';
    } elseif (mb_strlen($password) < 8) {
        $error = '密码至少 8 个字符';
    } elseif ($password !== $confirmPassword) {
        $error = '两次输入的密码不一致';
    } else {
        $pdo = getDB();

        // 检查用户名唯一
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() > 0) {
            $error = '该用户名已被注册';
        } else {
            // 检查邮箱唯一
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 0) {
                $error = '该邮箱已被注册';
            }
        }

        if (empty($error)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role, email_verified, verify_token, verify_token_expires) VALUES (?, ?, ?, 'user', 0, ?, ?)");
            $result = $stmt->execute([$username, $email, $hash, $token, $expires]);

            if ($result) {
                $mailSent = sendVerificationEmail($email, $username, $token);
                if ($mailSent) {
                    $success = '注册成功！验证邮件已发送至 ' . h($email) . '，请查收并完成验证后登录。';
                } else {
                    $success = '注册成功！但验证邮件发送失败，请联系管理员手动激活账户。';
                }
            } else {
                $error = '注册失败，请重试';
            }
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
    <title>注册 - <?php echo h(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/user.css?v=<?php echo ASSETS_VER; ?>">
    <?php renderSiteHead(); ?>
</head>
<body class="login-page">
    <div class="container">
        <div class="login-container">
            <h1 class="login-title">注册账号</h1>

            <?php if ($error): ?>
                <div class="alert-error"><?php echo h($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert-success"><?php echo $success; ?></div>
            <?php endif; ?>

            <?php if (!$success): ?>
            <form method="post" id="register-form">
                <?php echo csrf_input('register_form'); ?>
                <div class="form-group">
                    <label class="form-label">用户名</label>
                    <input type="text" name="username" class="form-input" required value="<?php echo h($_POST['username'] ?? ''); ?>" placeholder="2-30个字符">
                </div>

                <div class="form-group">
                    <label class="form-label">邮箱</label>
                    <input type="email" name="email" class="form-input" required value="<?php echo h($_POST['email'] ?? ''); ?>" placeholder="用于验证和找回密码">
                </div>

                <div class="form-group">
                    <label class="form-label">密码</label>
                    <input type="password" name="password" class="form-input" required placeholder="至少8个字符">
                </div>

                <div class="form-group">
                    <label class="form-label">确认密码</label>
                    <input type="password" name="confirm_password" class="form-input" required placeholder="再次输入密码">
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-full">注册</button>
                </div>
            </form>
            <?php endif; ?>

            <p class="login-back-link">
                已有账号？<a href="/login">去登录</a> | <a href="/">返回首页</a>
            </p>
        </div>
    </div>
    <script src="/assets/js/theme.js"></script>
    <?php if (needsCaptchaVerificationForAuth()): ?>
    <script>window.__captchaApiUrl = '/api/captcha.php';</script>
    <script src="/assets/js/captcha.js?v=<?php echo ASSETS_VER; ?>"></script>
    <script>
    (function() {
        var form = document.getElementById('register-form');
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
view('front/register.twig', ['page_html' => $pageHtml]);
