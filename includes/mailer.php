<?php
/**
 * 邮件发送封装
 * 优先使用 Resend HTTP API（走 HTTPS 443 端口，不受 SMTP 端口封锁影响）
 * 若未配置 Resend，回退到 PHPMailer SMTP
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/site_settings_loader.php';

/**
 * 获取当前邮件驱动类型
 * 优先从数据库读取，无值时从 config.php 判断
 */
function getMailDriver() {
    $driver = getSiteSetting('mail_driver', '');
    if ($driver) {
        return $driver;
    }
    // 自动判断：有 Resend API Key 就用 resend，否则用 smtp
    $apiKey = getSiteSetting('resend_api_key', defined('RESEND_API_KEY') ? RESEND_API_KEY : '');
    return !empty($apiKey) ? 'resend' : 'smtp';
}

/**
 * 通过 Resend HTTP API 发送邮件
 *
 * @param string $toEmail 收件人邮箱
 * @param string $toName 收件人名称
 * @param string $subject 邮件主题
 * @param string $htmlBody HTML 邮件内容
 * @param string $logPrefix 日志前缀
 * @return array ['success' => bool, 'error' => string]
 */
function sendViaResend($toEmail, $toName, $subject, $htmlBody, $logPrefix = '邮件') {
    $apiKey = getSiteSetting('resend_api_key', defined('RESEND_API_KEY') ? RESEND_API_KEY : '');
    $fromEmail = getSiteSetting('mail_from', MAIL_FROM);
    $fromName = getSiteSetting('mail_from_name', MAIL_FROM_NAME);

    if (empty($apiKey)) {
        return ['success' => false, 'error' => 'Resend API Key 未配置'];
    }

    $from = $fromName ? ($fromName . ' <' . $fromEmail . '>') : $fromEmail;
    $to = $toName ? ($toName . ' <' . $toEmail . '>') : $toEmail;

    $data = json_encode([
        'from'    => $from,
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $htmlBody,
    ]);

    // 最多尝试 2 次
    for ($attempt = 1; $attempt <= 2; $attempt++) {
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
            return ['success' => true, 'error' => ''];
        }

        // 解析错误信息
        $errorMsg = $curlError ?: $response;
        $decoded = json_decode($response, true);
        if ($decoded && isset($decoded['message'])) {
            $errorMsg = $decoded['message'];
        }

        error_log(sprintf(
            '%s发送失败 [Resend] (第%d次尝试): to=%s, http=%d, error=%s',
            $logPrefix, $attempt, $toEmail, $httpCode, $errorMsg
        ));
        if (function_exists('galLogError')) {
            galLogError('mail', 'Resend 发送失败', [
                'driver' => 'resend', 'attempt' => $attempt, 'to' => $toEmail,
                'http' => $httpCode, 'error' => $errorMsg, 'dep' => 'external',
            ]);
        }

        if ($attempt < 2) {
            usleep(500000); // 等待 0.5 秒后重试
        }
    }

    return ['success' => false, 'error' => $errorMsg ?? '未知错误'];
}

/**
 * 通过 PHPMailer SMTP 发送邮件（备用）
 */
function sendViaSMTP($toEmail, $toName, $subject, $htmlBody, $logPrefix = '邮件') {
    require_once __DIR__ . '/PHPMailer/Exception.php';
    require_once __DIR__ . '/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/PHPMailer/SMTP.php';

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = getSiteSetting('smtp_host', SMTP_HOST);
        $mail->SMTPAuth   = true;
        $mail->Username   = getSiteSetting('smtp_user', SMTP_USER);
        $mail->Password   = getSiteSetting('smtp_pass', SMTP_PASS);
        $mail->SMTPSecure = getSiteSetting('smtp_secure', SMTP_SECURE);
        $mail->Port       = intval(getSiteSetting('smtp_port', SMTP_PORT));
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 10;
        $mail->setFrom(
            getSiteSetting('mail_from', MAIL_FROM),
            getSiteSetting('mail_from_name', MAIL_FROM_NAME)
        );

        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $mail->send();
                return ['success' => true, 'error' => ''];
            } catch (\Exception $e) {
                $errorDetail = $e->getMessage();
                if (!empty($mail->ErrorInfo) && $mail->ErrorInfo !== $e->getMessage()) {
                    $errorDetail .= ' | ' . $mail->ErrorInfo;
                }
                error_log(sprintf(
                    '%s发送失败 [SMTP] (第%d次尝试): to=%s, error=%s',
                    $logPrefix, $attempt, $toEmail, $errorDetail
                ));
                if (function_exists('galLogError')) {
                    galLogError('mail', 'SMTP 发送失败', [
                        'driver' => 'smtp', 'attempt' => $attempt, 'to' => $toEmail,
                        'error' => $errorDetail, 'dep' => 'external',
                    ]);
                }
                if ($attempt < 2) {
                    $mail->getSMTPInstance()->close();
                    sleep(1);
                }
            }
        }
        return ['success' => false, 'error' => $errorDetail ?? '发送失败'];
    } catch (\Exception $e) {
        error_log($logPrefix . '创建失败 [SMTP]: to=' . $toEmail . ', error=' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * 统一邮件发送入口
 * 根据配置自动选择 Resend 或 SMTP
 */
function sendMail($toEmail, $toName, $subject, $htmlBody, $logPrefix = '邮件') {
    $driver = getMailDriver();

    if ($driver === 'resend') {
        return sendViaResend($toEmail, $toName, $subject, $htmlBody, $logPrefix);
    }
    return sendViaSMTP($toEmail, $toName, $subject, $htmlBody, $logPrefix);
}

/**
 * 发送邮箱验证邮件
 */
function sendVerificationEmail($email, $username, $token) {
    $verifyUrl = SITE_URL . '/verify_email?token=' . urlencode($token);
    $siteName = SITE_NAME;

    $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#333;">'
        . '<h2 style="color:#7c3aed;">' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<p>你好，<strong>' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</strong>！</p>'
        . '<p>感谢注册。请点击下方按钮验证你的邮箱地址：</p>'
        . '<p style="text-align:center;margin:30px 0;">'
        . '<a href="' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:12px 32px;background:linear-gradient(135deg,#7c3aed,#db2777);color:#fff;text-decoration:none;border-radius:6px;font-size:16px;">验证邮箱</a>'
        . '</p>'
        . '<p>或复制以下链接到浏览器打开：</p>'
        . '<p style="word-break:break-all;color:#666;font-size:13px;">' . htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="color:#999;font-size:12px;margin-top:30px;">此链接 24 小时内有效。如果不是你本人操作，请忽略此邮件。</p>'
        . '</body></html>';

    $result = sendMail($email, $username, "[$siteName] 邮箱验证", $body, '验证邮件');
    return $result['success'];
}

/**
 * 发送账号已验证通知邮件（管理员手动验证后通知用户）
 */
function sendAccountVerifiedEmail($email, $username) {
    $siteName = SITE_NAME;
    $loginUrl = SITE_URL . '/login';

    $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#333;">'
        . '<h2 style="color:#7c3aed;">' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<p>你好，<strong>' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</strong>！</p>'
        . '<p>您的账号已由管理员完成邮箱验证，可正常使用全站功能。</p>'
        . '<p style="text-align:center;margin:30px 0;">'
        . '<a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:12px 32px;background:linear-gradient(135deg,#7c3aed,#db2777);color:#fff;text-decoration:none;border-radius:6px;font-size:16px;">前往登录</a>'
        . '</p>'
        . '<p style="color:#999;font-size:12px;margin-top:30px;">如有疑问，请联系站点管理员。</p>'
        . '</body></html>';

    $result = sendMail($email, $username, "[$siteName] 您的邮箱已完成验证", $body, '验证通知邮件');
    return $result['success'];
}

