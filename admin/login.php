<?php
require_once '../includes/config.php';
require_once '../includes/csrf.php';
require_once '../includes/captcha.php';
require_once '../includes/view.php';

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $cookieParams = session_get_cookie_params();
    $sessionLifetime = 60 * 60 * 24 * 30;
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $baseDomain = 'galerror.top';
    $cookieDomain = ($host === $baseDomain || str_ends_with($host, '.' . $baseDomain)) ? '.' . $baseDomain : ($cookieParams['domain'] ?: '');
    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    ini_set('session.cookie_lifetime', (string)$sessionLifetime);
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path' => $cookieParams['path'] ?: '/',
        'domain' => $cookieDomain,
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// 如果已经登录，跳转到控制台
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate_request('admin_login_form')) {
        $error = '请求已过期，请刷新后重试';
    } else {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = '请输入用户名和密码';
    } else {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND role IN ('sub','super')");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        
        if ($admin && password_verify($password, $admin['password'])) {
            // 检查账户是否启用
            if (isset($admin['enabled']) && !$admin['enabled']) {
                $error = '该账户已被禁用';
            } else {
                session_regenerate_id(true);
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_role'] = $admin['role'] ?? 'super';
                $_SESSION['admin_avatar'] = $admin['avatar'] ?? '';
                $_SESSION['admin_permissions'] = $admin['permissions'] ?? '';
                
                // 同步设置前端用户 session
                $_SESSION['user_logged_in'] = true;
                $_SESSION['user_id'] = (int)$admin['id'];
                $_SESSION['user_username'] = $admin['username'];
                $_SESSION['user_role'] = $admin['role'];
                $_SESSION['user_avatar'] = $admin['avatar'] ?? '';
                $_SESSION['user_email'] = $admin['email'] ?? '';
                
                // 自动通过人机验证（管理员登录即可信）
                $_SESSION['captcha_verified'] = true;
                $_SESSION['captcha_verified_at'] = time();

                try {
                    $pdo->prepare("UPDATE users SET last_activity_at = NOW() WHERE id = ?")->execute([(int)$admin['id']]);
                    $_SESSION['admin_last_activity_touch'] = time();
                    $_SESSION['user_last_activity_touch'] = time();
                    $_SESSION['user_login_ip'] = function_exists('getClientIP') ? getClientIP() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
                } catch (Exception $e) {
                }
                
                header('Location: index.php');
                exit;
            }
        } else {
            $error = '用户名或密码错误';
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
    <title>管理员登录 - <?php echo h(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <script>(function(){var t;try{t=localStorage.getItem("galerror-theme")}catch(e){}if(!t){t=window.matchMedia&&window.matchMedia("(prefers-color-scheme:light)").matches?"light":"dark"}document.documentElement.setAttribute("data-theme",t)})()</script>
</head>
<body class="login-page">
    <div class="container">
        <div class="login-container">
            <h1 class="login-title">管理员登录</h1>
            
            <?php if ($error): ?>
                <div class="alert-error">
                    <?php echo h($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="post">
                <?php echo csrf_input('admin_login_form'); ?>
                <div class="form-group">
                    <label class="form-label">用户名</label>
                    <input type="text" name="username" class="form-input" required autofocus>
                </div>
                
                <div class="form-group">
                    <label class="form-label">密码</label>
                    <input type="password" name="password" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-full">登录</button>
                </div>
            </form>
            
            <p class="login-back-link">
                <a href="/">返回首页</a>
            </p>
        </div>
    </div>
    <script src="/assets/js/theme.js"></script>
    <?php if (!isCaptchaVerified()): ?>
    <script>window.__captchaApiUrl = '/api/captcha.php';</script>
    <script>window.__captchaRequired = true;</script>
    <script src="/assets/js/captcha.js?v=<?php echo ASSETS_VER; ?>"></script>
    <?php endif; ?>
</body>
</html>
<?php
$pageHtml = ob_get_clean();
view('admin/login.twig', ['page_html' => $pageHtml]);
