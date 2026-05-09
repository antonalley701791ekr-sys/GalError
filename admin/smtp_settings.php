<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/site_settings_loader.php';

checkLogin();

// 仅超级管理员可访问
if (!isSuperAdmin()) {
    $_SESSION['admin_msg'] = '您没有权限执行此操作';
    $_SESSION['admin_msg_type'] = 'error';
    header('Location: index.php');
    exit;
}

$pdo = getDB();
$message = '';
$messageType = '';

// 获取所有邮件相关设置
function getMailSettings($pdo) {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'smtp_%' OR setting_key LIKE 'mail_%' OR setting_key LIKE 'resend_%'");
    $settings = [];
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

function setSetting($pdo, $key, $value) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM site_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    if ($stmt->fetch()['c'] > 0) {
        $pdo->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = ?")->execute([$value, $key]);
    } else {
        $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, $value]);
    }
}

$settings = getMailSettings($pdo);
$action = $_POST['action'] ?? '';

// 当前驱动
$currentDriver = $settings['mail_driver'] ?? '';
if (!$currentDriver) {
    $apiKey = $settings['resend_api_key'] ?? (defined('RESEND_API_KEY') ? RESEND_API_KEY : '');
    $currentDriver = !empty($apiKey) ? 'resend' : 'smtp';
}

// 保存设置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
    $driver = trim($_POST['mail_driver'] ?? 'resend');
    setSetting($pdo, 'mail_driver', $driver);
    setSetting($pdo, 'mail_from', trim($_POST['mail_from'] ?? ''));
    setSetting($pdo, 'mail_from_name', trim($_POST['mail_from_name'] ?? ''));

    // Resend 配置
    $newApiKey = $_POST['resend_api_key'] ?? '';
    if ($newApiKey !== '') {
        setSetting($pdo, 'resend_api_key', trim($newApiKey));
    }

    // SMTP 配置
    setSetting($pdo, 'smtp_host', trim($_POST['smtp_host'] ?? ''));
    setSetting($pdo, 'smtp_port', trim($_POST['smtp_port'] ?? '465'));
    setSetting($pdo, 'smtp_secure', trim($_POST['smtp_secure'] ?? 'ssl'));
    setSetting($pdo, 'smtp_user', trim($_POST['smtp_user'] ?? ''));
    $newPass = $_POST['smtp_pass'] ?? '';
    if ($newPass !== '') {
        setSetting($pdo, 'smtp_pass', $newPass);
    }

    $message = '设置已保存';
    $messageType = 'success';
    $settings = getMailSettings($pdo);
    $currentDriver = $driver;
}

// 发送测试邮件
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'test') {
    set_time_limit(30);
    $testEmail = trim($_POST['test_email'] ?? '');

    if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        $message = '请输入有效的收件人邮箱地址';
        $messageType = 'error';
    } else {
        $driver = $settings['mail_driver'] ?? $currentDriver;

        if ($driver === 'resend') {
            // Resend API 测试
            $apiKey = $settings['resend_api_key'] ?? (defined('RESEND_API_KEY') ? RESEND_API_KEY : '');
            $fromEmail = $settings['mail_from'] ?? MAIL_FROM;
            $fromName = $settings['mail_from_name'] ?? MAIL_FROM_NAME;

            if (empty($apiKey)) {
                $message = 'Resend API Key 未配置';
                $messageType = 'error';
            } else {
                $from = $fromName ? ($fromName . ' <' . $fromEmail . '>') : $fromEmail;
                $data = json_encode([
                    'from'    => $from,
                    'to'      => [$testEmail],
                    'subject' => '[' . SITE_NAME . '] 邮件测试',
                    'html'    => '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#333;">'
                        . '<h2 style="color:#7c3aed;">' . htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') . '</h2>'
                        . '<p>这是一封测试邮件，用于验证邮件配置是否正确。</p>'
                        . '<p style="color:#999;font-size:12px;margin-top:30px;">发送时间：' . date('Y-m-d H:i:s') . '</p>'
                        . '</body></html>',
                ]);

                $ch = curl_init('https://api.resend.com/emails');
                curl_setopt_array($ch, [
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: Bearer ' . $apiKey,
                        'Content-Type: application/json',
                    ],
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $data,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 15,
                    CURLOPT_CONNECTTIMEOUT => 5,
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($httpCode === 200) {
                    $message = '测试邮件已成功发送至 ' . htmlspecialchars($testEmail, ENT_QUOTES, 'UTF-8');
                    $messageType = 'success';
                } else {
                    $errorMsg = $curlError ?: $response;
                    $decoded = json_decode($response, true);
                    if ($decoded && isset($decoded['message'])) {
                        $errorMsg = $decoded['message'];
                    }
                    $message = '发送失败（HTTP ' . $httpCode . '）：' . htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8')
                        . '<br><br><strong>当前配置：</strong>'
                        . '<br>驱动：Resend API'
                        . '<br>发件人：' . htmlspecialchars($from, ENT_QUOTES, 'UTF-8')
                        . '<br>API Key：' . htmlspecialchars(substr($apiKey, 0, 10) . '...', ENT_QUOTES, 'UTF-8');
                    $messageType = 'error';
                }
            }
        } else {
            // SMTP 测试
            require_once '../includes/PHPMailer/Exception.php';
            require_once '../includes/PHPMailer/PHPMailer.php';
            require_once '../includes/PHPMailer/SMTP.php';

            $smtpHost = $settings['smtp_host'] ?? SMTP_HOST;
            $smtpPort = intval($settings['smtp_port'] ?? SMTP_PORT);

            // 先做端口连通性检测
            $connTest = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 5);
            if (!$connTest) {
                $message = '连接失败：无法连接到 ' . htmlspecialchars($smtpHost, ENT_QUOTES, 'UTF-8') . ':' . $smtpPort
                    . '<br>错误：' . htmlspecialchars($errstr, ENT_QUOTES, 'UTF-8')
                    . '<br><br><strong>提示：</strong>如果出站 SMTP 端口被封锁，请切换到 Resend API 模式。';
                $messageType = 'error';
            } else {
                fclose($connTest);
                try {
                    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host       = $smtpHost;
                    $mail->SMTPAuth   = true;
                    $mail->Username   = $settings['smtp_user'] ?? SMTP_USER;
                    $mail->Password   = $settings['smtp_pass'] ?? SMTP_PASS;
                    $mail->SMTPSecure = $settings['smtp_secure'] ?? SMTP_SECURE;
                    $mail->Port       = $smtpPort;
                    $mail->CharSet    = 'UTF-8';
                    $mail->Timeout    = 10;
                    $mail->setFrom(
                        $settings['mail_from'] ?? MAIL_FROM,
                        $settings['mail_from_name'] ?? MAIL_FROM_NAME
                    );
                    $mail->addAddress($testEmail);
                    $mail->isHTML(true);
                    $mail->Subject = '[' . SITE_NAME . '] SMTP 测试邮件';
                    $mail->Body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#333;">'
                        . '<h2 style="color:#7c3aed;">' . htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') . '</h2>'
                        . '<p>这是一封测试邮件，用于验证 SMTP 配置是否正确。</p>'
                        . '<p style="color:#999;font-size:12px;margin-top:30px;">发送时间：' . date('Y-m-d H:i:s') . '</p>'
                        . '</body></html>';
                    $mail->send();
                    $message = '测试邮件已成功发送至 ' . htmlspecialchars($testEmail, ENT_QUOTES, 'UTF-8');
                    $messageType = 'success';
                } catch (\Exception $e) {
                    $message = '发送失败：' . htmlspecialchars($mail->ErrorInfo, ENT_QUOTES, 'UTF-8');
                    $messageType = 'error';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>邮箱设置 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <?php renderAdminHeadScript(); ?>
</head>
<body class="has-fixed-nav">
    <?php include '../includes/header.php'; ?>
    <div class="admin-layout">
        <?php renderAdminSidebar('smtp_settings.php'); ?>

        <div class="admin-content">
            <main class="admin-main">
                <div class="admin-page-header">
                    <h1>邮箱设置</h1>
                </div>
                <?php if ($message): ?>
                    <div class="admin-alert-<?php echo $messageType; ?>">
                        <?php echo $message; ?>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="action" value="save">

                    <!-- 邮件驱动选择 -->
                    <div class="card settings-section">
                        <div class="card-header">邮件发送方式</div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="form-label">发送驱动</label>
                                <select name="mail_driver" class="form-input" id="mailDriverSelect" onchange="toggleDriverSections()">
                                    <option value="resend" <?php echo $currentDriver === 'resend' ? 'selected' : ''; ?>>Resend API（推荐，走 HTTPS 不受端口限制）</option>
                                    <option value="smtp" <?php echo $currentDriver === 'smtp' ? 'selected' : ''; ?>>SMTP（需要出站 465/587 端口）</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Resend API 配置 -->
                    <div class="card settings-section" id="resendSection">
                        <div class="card-header">Resend API 配置</div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="form-label">API Key</label>
                                <input type="password" name="resend_api_key" class="form-input" value="" placeholder="<?php echo !empty($settings['resend_api_key']) ? '已设置（留空保留原值）' : '请输入 Resend API Key'; ?>">
                                <?php if (!empty($settings['resend_api_key'])): ?>
                                    <p class="form-hint">API Key 已保存，留空提交将保留当前值</p>
                                <?php elseif (defined('RESEND_API_KEY') && RESEND_API_KEY): ?>
                                    <p class="form-hint">当前使用 config.php 中的默认值</p>
                                <?php endif; ?>
                            </div>
                            <div class="info-block" style="font-size:0.85rem;">
                                <strong>使用说明：</strong>
                                <br>1. 注册 <a href="https://resend.com" target="_blank" style="color:var(--accent-purple);">resend.com</a> 账号
                                <br>2. 添加并验证域名（添加 DNS 记录）
                                <br>3. 创建 API Key 填入上方
                                <br>4. 发件人邮箱域名需与 Resend 中验证的域名一致
                            </div>
                        </div>
                    </div>

                    <!-- SMTP 配置 -->
                    <div class="card settings-section" id="smtpSection">
                        <div class="card-header">SMTP 服务器配置</div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="form-label">SMTP 服务器地址</label>
                                <input type="text" name="smtp_host" class="form-input" value="<?php echo h($settings['smtp_host'] ?? ''); ?>" placeholder="例如：smtp.qq.com">
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                                <div class="form-group">
                                    <label class="form-label">端口</label>
                                    <input type="number" name="smtp_port" class="form-input" value="<?php echo h($settings['smtp_port'] ?? '465'); ?>" placeholder="465">
                                    <p class="form-hint">SSL 通常用 465，TLS 通常用 587</p>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">加密方式</label>
                                    <?php $currentSecure = $settings['smtp_secure'] ?? 'ssl'; ?>
                                    <select name="smtp_secure" class="form-input">
                                        <option value="ssl" <?php echo $currentSecure === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                        <option value="tls" <?php echo $currentSecure === 'tls' ? 'selected' : ''; ?>>TLS</option>
                                        <option value="" <?php echo $currentSecure === '' ? 'selected' : ''; ?>>无加密</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">用户名</label>
                                <input type="text" name="smtp_user" class="form-input" value="<?php echo h($settings['smtp_user'] ?? ''); ?>" placeholder="SMTP 登录账号（通常为邮箱地址）">
                            </div>
                            <div class="form-group">
                                <label class="form-label">密码 / 授权码</label>
                                <input type="password" name="smtp_pass" class="form-input" value="" placeholder="<?php echo !empty($settings['smtp_pass']) ? '已设置（留空保留原密码）' : '请输入密码或授权码'; ?>">
                                <?php if (!empty($settings['smtp_pass'])): ?>
                                    <p class="form-hint">密码已保存，留空提交将保留当前密码</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- 发件人信息 -->
                    <div class="card settings-section">
                        <div class="card-header">发件人信息</div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="form-label">发件人邮箱</label>
                                <input type="email" name="mail_from" class="form-input" value="<?php echo h($settings['mail_from'] ?? ''); ?>" placeholder="例如：noreply@galerror.top">
                                <p class="form-hint">使用 Resend 时，域名需与 Resend 中验证的域名一致</p>
                            </div>
                            <div class="form-group">
                                <label class="form-label">发件人名称</label>
                                <input type="text" name="mail_from_name" class="form-input" value="<?php echo h($settings['mail_from_name'] ?? ''); ?>" placeholder="例如：<?php echo h(SITE_NAME); ?>">
                            </div>
                        </div>
                    </div>

                    <div style="margin-bottom: 30px;">
                        <button type="submit" class="btn">保存设置</button>
                    </div>
                </form>

                <!-- 测试邮件 -->
                <form method="post">
                    <input type="hidden" name="action" value="test">
                    <div class="card settings-section">
                        <div class="card-header">发送测试邮件</div>
                        <div class="card-body">
                            <p class="form-hint" style="margin-bottom: 12px;">
                                保存配置后，输入收件人邮箱发送测试邮件以验证配置是否正确。
                                当前使用：<strong><?php echo $currentDriver === 'resend' ? 'Resend API' : 'SMTP'; ?></strong>
                            </p>
                            <div class="form-group">
                                <label class="form-label">收件人邮箱</label>
                                <div style="display: flex; gap: 12px; align-items: flex-start;">
                                    <input type="email" name="test_email" class="form-input" placeholder="输入收件人邮箱地址" required style="flex: 1;">
                                    <button type="submit" class="btn btn-secondary">发送测试</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </main>
        </div>
    </div>
    <?php renderAdminFooterScripts(); ?>
    <script>
    function toggleDriverSections() {
        var driver = document.getElementById('mailDriverSelect').value;
        document.getElementById('resendSection').style.display = driver === 'resend' ? '' : 'none';
        document.getElementById('smtpSection').style.display = driver === 'smtp' ? '' : 'none';
    }
    toggleDriverSections();
    </script>
</body>
</html>

