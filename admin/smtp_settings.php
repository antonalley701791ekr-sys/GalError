<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/view.php';
require_once '../includes/smtp_settings/service.php';
require_once '../includes/smtp_settings/actions.php';

checkLogin(); if (!isSuperAdmin()) { $_SESSION['admin_msg']='您没有权限执行此操作'; $_SESSION['admin_msg_type']='error'; header('Location: index.php'); exit; }
$pdo = getDB(); $message=''; $messageType=''; $context = loadSmtpSettingsContext($pdo); $settings = $context['settings']; $currentDriver = $context['currentDriver']; $action = $_POST['action'] ?? ''; if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') { $r = handleSmtpSettingsSave($pdo, $_POST, $settings); $message = $r['message']; $messageType = $r['messageType']; $context = loadSmtpSettingsContext($pdo); $settings = $context['settings']; $currentDriver = $context['currentDriver']; } if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'test') { $r = handleSmtpSettingsTest($pdo, $_POST, $settings, $currentDriver); $message = $r['message']; $messageType = $r['messageType']; }
view('admin/smtp_settings.twig', ['page_title'=>'邮箱设置','admin_css_mtime'=>@filemtime(BASE_PATH . '/assets/css/style.css') ?: time(),'message'=>$message,'message_type'=>$messageType,'settings'=>$settings,'currentDriver'=>$currentDriver]);
