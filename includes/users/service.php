<?php
require_once __DIR__ . '/action_json.php';
require_once __DIR__ . '/query.php';
require_once __DIR__ . '/forms.php';

function usersHandleRequest(PDO $pdo, string $action): array {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postAction = $_POST['action'] ?? '';
        $id = isset($_POST['id']) ? (int)$_POST['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
        if (in_array($postAction, ['ban_user', 'unban_user', 'revoke_admin'], true) && $id > 0) {
            $result = usersHandleModerationAction($pdo, $postAction, $id);
            $_SESSION['message'] = $result['message'] ?? '';
            $_SESSION['message_type'] = $result['messageType'] ?? 'success';
            header('Location: /admin/users.php');
            exit;
        }
        if ($postAction === 'verify_admin_email') {
            header('Location: /admin/users.php');
            exit;
        }
    }
    usersHandleAjax($pdo);
    $context = usersGetContext($pdo);
    $formContext = usersGetFormContext($pdo, $action, isset($_GET['id']) ? (int)$_GET['id'] : null);
    return array_merge($context, $formContext, ['action' => $action]);
}
