<?php
/**
 * 极简验证码引导文件
 * 仅供验证码接口使用，避免加载主站重型配置与自动迁移逻辑
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__) . '/');
}

if (!defined('CAPTCHA_LOG_FILE')) {
    define('CAPTCHA_LOG_FILE', BASE_PATH . 'data/captcha.log');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
