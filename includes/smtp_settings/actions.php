<?php
function smtpSettingsSet(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare('SELECT COUNT(*) as c FROM site_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    if ((int)($stmt->fetch()['c'] ?? 0) > 0) {
        $pdo->prepare('UPDATE site_settings SET setting_value = ? WHERE setting_key = ?')->execute([$value, $key]);
    } else {
        $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)')->execute([$value, $key]);
    }
}

function smtpSettingsBuildContext(PDO $pdo): array {
    return loadSmtpSettingsQueryContext($pdo);
}

function handleSmtpSettingsSave(PDO $pdo, array $input, array $settings): array {
    $driver = trim($input['mail_driver'] ?? 'resend');
    smtpSettingsSet($pdo, 'mail_driver', $driver);
    smtpSettingsSet($pdo, 'mail_from', trim($input['mail_from'] ?? ''));
    smtpSettingsSet($pdo, 'mail_from_name', trim($input['mail_from_name'] ?? ''));
    if (($input['resend_api_key'] ?? '') !== '') smtpSettingsSet($pdo, 'resend_api_key', trim($input['resend_api_key']));
    smtpSettingsSet($pdo, 'smtp_host', trim($input['smtp_host'] ?? ''));
    smtpSettingsSet($pdo, 'smtp_port', trim($input['smtp_port'] ?? '465'));
    smtpSettingsSet($pdo, 'smtp_secure', trim($input['smtp_secure'] ?? 'ssl'));
    smtpSettingsSet($pdo, 'smtp_user', trim($input['smtp_user'] ?? ''));
    if (($input['smtp_pass'] ?? '') !== '') smtpSettingsSet($pdo, 'smtp_pass', $input['smtp_pass']);
    return ['message' => '设置已保存', 'messageType' => 'success'];
}

function handleSmtpSettingsTest(PDO $pdo, array $input, array $settings, string $currentDriver): array {
    $testEmail = trim($input['test_email'] ?? '');
    if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) return ['message' => '请输入有效的收件人邮箱地址', 'messageType' => 'error'];
    $driver = $settings['mail_driver'] ?? $currentDriver;
    if ($driver === 'resend') {
        $apiKey = $settings['resend_api_key'] ?? (defined('RESEND_API_KEY') ? RESEND_API_KEY : '');
        $fromEmail = $settings['mail_from'] ?? MAIL_FROM; $fromName = $settings['mail_from_name'] ?? MAIL_FROM_NAME;
        if (empty($apiKey)) return ['message' => 'Resend API Key 未配置', 'messageType' => 'error'];
        $from = $fromName ? ($fromName . ' <' . $fromEmail . '>') : $fromEmail;
        $data = json_encode(['from' => $from, 'to' => [$testEmail], 'subject' => '[' . SITE_NAME . '] 邮件测试', 'html' => '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#333;"><h2 style="color:#7c3aed;">' . htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') . '</h2><p>这是一封测试邮件，用于验证邮件配置是否正确。</p><p style="color:#999;font-size:12px;margin-top:30px;">发送时间：' . date('Y-m-d H:i:s') . '</p></body></html>']);
        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'], CURLOPT_POST => true, CURLOPT_POSTFIELDS => $data, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5]);
        $response = curl_exec($ch); $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); $curlError = curl_error($ch); curl_close($ch);
        if ($httpCode === 200) return ['message' => '测试邮件已成功发送至 ' . htmlspecialchars($testEmail, ENT_QUOTES, 'UTF-8'), 'messageType' => 'success'];
        $errorMsg = $curlError ?: $response; $decoded = json_decode($response, true); if ($decoded && isset($decoded['message'])) $errorMsg = $decoded['message'];
        return ['message' => '发送失败（HTTP ' . $httpCode . '）：' . htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') . '<br><br><strong>当前配置：</strong><br>驱动：Resend API<br>发件人：' . htmlspecialchars($from, ENT_QUOTES, 'UTF-8') . '<br>API Key：' . htmlspecialchars(substr($apiKey, 0, 10) . '...', ENT_QUOTES, 'UTF-8'), 'messageType' => 'error'];
    }

    require_once '../includes/PHPMailer/Exception.php'; require_once '../includes/PHPMailer/PHPMailer.php'; require_once '../includes/PHPMailer/SMTP.php';
    $smtpHost = $settings['smtp_host'] ?? SMTP_HOST; $smtpPort = intval($settings['smtp_port'] ?? SMTP_PORT); $connTest = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 5);
    if (!$connTest) return ['message' => '连接失败：无法连接到 ' . htmlspecialchars($smtpHost, ENT_QUOTES, 'UTF-8') . ':' . $smtpPort . '<br>错误：' . htmlspecialchars($errstr, ENT_QUOTES, 'UTF-8') . '<br><br><strong>提示：</strong>如果出站 SMTP 端口被封锁，请切换到 Resend API 模式。', 'messageType' => 'error'];
    fclose($connTest);
    try { $mail = new \PHPMailer\PHPMailer\PHPMailer(true); $mail->isSMTP(); $mail->Host = $smtpHost; $mail->SMTPAuth = true; $mail->Username = $settings['smtp_user'] ?? SMTP_USER; $mail->Password = $settings['smtp_pass'] ?? SMTP_PASS; $mail->SMTPSecure = $settings['smtp_secure'] ?? SMTP_SECURE; $mail->Port = $smtpPort; $mail->CharSet = 'UTF-8'; $mail->Timeout = 10; $mail->setFrom($settings['mail_from'] ?? MAIL_FROM, $settings['mail_from_name'] ?? MAIL_FROM_NAME); $mail->addAddress($testEmail); $mail->isHTML(true); $mail->Subject = '[' . SITE_NAME . '] SMTP 测试邮件'; $mail->Body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#333;"><h2 style="color:#7c3aed;">' . htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') . '</h2><p>这是一封测试邮件，用于验证 SMTP 配置是否正确。</p><p style="color:#999;font-size:12px;margin-top:30px;">发送时间：' . date('Y-m-d H:i:s') . '</p></body></html>'; $mail->send(); return ['message' => '测试邮件已成功发送至 ' . htmlspecialchars($testEmail, ENT_QUOTES, 'UTF-8'), 'messageType' => 'success']; } catch (\Exception $e) { return ['message' => '发送失败：' . htmlspecialchars($mail->ErrorInfo, ENT_QUOTES, 'UTF-8'), 'messageType' => 'error']; }
}
