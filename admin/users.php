<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/user_auth.php';
require_once '../includes/mailer.php';
require_once '../includes/view.php';
require_once '../includes/users/service.php';

checkLogin();
requireSuperAdmin();

$pdo = getDB();
$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$context = usersHandleRequest($pdo, $action);

view('admin/users.twig', array_merge([
    'page_title' => '管理员设置',
    'admin_css_mtime' => @filemtime(BASE_PATH . '/assets/css/style.css') ?: time(),
    'message' => '',
    'message_type' => '',
], $context));
