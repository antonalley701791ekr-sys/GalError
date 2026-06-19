<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/view.php';
require_once '../includes/documents/service.php';

checkLogin(); requirePermission('site', 'view');
$pdo = getDB(); $action = $_GET['action'] ?? ''; $id = intval($_GET['id'] ?? 0); $message=''; $messageType='';
if ($_SERVER['REQUEST_METHOD'] === 'POST') { requirePermission('site', 'edit'); $postAction = $_POST['action'] ?? ''; if ($postAction === 'add') $r = handleDocumentsAdd($pdo, $_POST, $_FILES); elseif ($postAction === 'edit') $r = handleDocumentsEdit($pdo, intval($_POST['id'] ?? 0), $_POST, $_FILES); elseif ($postAction === 'delete') $r = handleDocumentsDelete($pdo, intval($_GET['id'] ?? 0)); elseif ($postAction === 'toggle') $r = handleDocumentsToggle($pdo, intval($_GET['id'] ?? 0)); elseif ($postAction === 'upload_image') { header('Content-Type: application/json'); echo json_encode(handleDocumentsUploadImage($_FILES['image'] ?? [])); exit; } else $r = ['message' => '', 'messageType' => '']; $message = $r['message']; $messageType = $r['messageType']; }
$context = loadDocumentsContext($pdo, ['action' => $action, 'id' => $id]);
view('admin/documents.twig', ['page_title' => '文档管理','admin_css_mtime' => @filemtime(BASE_PATH . '/assets/css/style.css') ?: time(),'message'=>$message,'message_type'=>$messageType] + $context);
