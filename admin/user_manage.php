<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/user_auth.php';
require_once '../includes/mailer.php';
require_once '../includes/view.php';
require_once '../includes/user_manage/service.php';
require_once '../includes/user_manage/actions.php';

checkLogin();
requirePermission('users', 'view');

$pdo = getDB();
$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    if ($action === 'online_snapshot') {
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(handleUserManageOnlineSnapshot($pdo, $input)); exit;
    } elseif ($action === 'verify_email') {
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(handleUserManageVerifyEmail($pdo, $input)); exit;
    } elseif ($action === 'batch_verify') {
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(handleUserManageBatchVerify($pdo, $input)); exit;
    } elseif ($action === 'delete_user') {
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(handleUserManageDeleteUser($pdo, $input)); exit;
    } elseif ($action === 'upgrade_to_sub') {
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(handleUserManageUpgradeToSub($pdo, $input, userManagePermissionModules())); exit;
    } elseif ($action === 'revoke_admin') {
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(handleUserManageRevokeAdmin($pdo, $input)); exit;
    } elseif ($action === 'reset_password') {
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(handleUserManageResetPassword($pdo, $input)); exit;
    } elseif ($action === 'ban_user') {
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(handleUserManageBanUser($pdo, $input)); exit;
    } elseif ($action === 'unban_user') {
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(handleUserManageUnbanUser($pdo, $input)); exit;
    } elseif ($action === 'verify_admin_email') {
        header('Content-Type: application/json; charset=utf-8'); echo json_encode(handleUserManageVerifyAdminEmail($pdo, $input)); exit;
    }
}

$filters = ['search_type' => $_GET['search_type'] ?? '', 'keyword' => $_GET['keyword'] ?? '', 'role_filter' => $_GET['role_filter'] ?? '', 'page' => $_GET['page'] ?? 1];
$context = loadUserManageContext($pdo, $filters);
view('admin/user_manage.twig', ['page_title' => '用户管理', 'admin_css_mtime' => @filemtime(BASE_PATH . '/assets/css/style.css') ?: time(), 'message' => $message, 'message_type' => $messageType, 'user_manage_js_mtime' => @filemtime(BASE_PATH . '/assets/js/user-manage.js') ?: time()] + $context);
