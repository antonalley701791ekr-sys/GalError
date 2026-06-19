<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/view.php';
require_once '../includes/admin_settings/query.php';
require_once '../includes/admin_settings/actions.php';

checkLogin(); $pdo = getDB(); $adminId = $_SESSION['admin_id']; $message=''; $messageType=''; $context = loadAdminSettingsQueryContext($pdo, $adminId); $action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'change_password') { $r = handleAdminSettingsChangePassword($pdo, $adminId, $_POST); }
    elseif ($action === 'change_username') { $r = handleAdminSettingsChangeUsername($pdo, $adminId, $_POST); }
    elseif ($action === 'upload_avatar') { $r = handleAdminSettingsUploadAvatar($pdo, $adminId, $_FILES['avatar'] ?? []); }
    elseif ($action === 'delete_avatar') { $r = handleAdminSettingsDeleteAvatar($pdo, $adminId); }
    else { $r = ['message' => '', 'messageType' => '']; }
    $message = $r['message']; $messageType = $r['messageType']; $context = loadAdminSettingsQueryContext($pdo, $adminId);
}
view('admin/admin_settings.twig', ['page_title'=>'个人设置','admin_css_mtime'=>@filemtime(BASE_PATH . '/assets/css/style.css') ?: time(),'message'=>$message,'message_type'=>$messageType] + $context);
