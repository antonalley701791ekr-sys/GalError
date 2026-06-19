<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/view.php';
require_once '../includes/site_settings/query.php';
require_once '../includes/site_settings/actions.php';

checkLogin(); requirePermission('site', 'view');
$pdo = getDB(); $message=''; $messageType=''; $context = loadSiteSettingsQueryContext($pdo); $settings = $context['settings'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') { requirePermission('site', 'edit'); $r = handleSiteSettingsSave($pdo, $_POST, $_FILES, $settings); $message = $r['message']; $messageType = $r['messageType']; $context = loadSiteSettingsQueryContext($pdo); }
view('admin/site_settings.twig', ['page_title'=>'站点外观','admin_css_mtime'=>@filemtime(BASE_PATH . '/assets/css/style.css') ?: time(),'message'=>$message,'message_type'=>$messageType] + $context);
