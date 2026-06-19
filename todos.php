<?php
require_once 'includes/user_auth.php';
require_once 'includes/view.php';
requireUserLogin();

$pdo = getDB();
$status = $_GET['status'] ?? '';
$where = [];
$params = [];

if ($status && in_array($status, ['pending', 'completed', 'cancelled'], true)) {
    $where[] = 'status = ?';
    $params[] = $status;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $pdo->prepare("SELECT * FROM todos {$whereClause} ORDER BY sort_order ASC, created_at DESC");
$stmt->execute($params);
$todos = $stmt->fetchAll();

$todoStats = [
    'total' => (int)$pdo->query("SELECT COUNT(*) as c FROM todos")->fetch()['c'],
    'pending' => (int)$pdo->query("SELECT COUNT(*) as c FROM todos WHERE status = 'pending'")->fetch()['c'],
    'completed' => (int)$pdo->query("SELECT COUNT(*) as c FROM todos WHERE status = 'completed'")->fetch()['c'],
    'cancelled' => (int)$pdo->query("SELECT COUNT(*) as c FROM todos WHERE status = 'cancelled'")->fetch()['c'],
];

$statusText = ['pending' => '进行中', 'completed' => '已完成', 'cancelled' => '已取消'];
$statusClassMap = ['pending' => 'status-pending', 'completed' => 'status-approved', 'cancelled' => 'status-cancelled'];

foreach ($todos as &$todo) {
    $todo['status_class'] = $statusClassMap[$todo['status']] ?? 'status-pending';
    $todo['status_text'] = $statusText[$todo['status']] ?? (string)$todo['status'];
    $todo['author_text'] = !empty($todo['author']) ? (string)$todo['author'] : '管理员';
    $todo['created_text'] = !empty($todo['created_at']) ? date('Y-m-d H:i', strtotime($todo['created_at'])) : '';
    $todo['completed_text'] = !empty($todo['completed_at']) ? date('Y-m-d H:i', strtotime($todo['completed_at'])) : '未完成';
    $todo['description_html'] = nl2br(h((string)($todo['description'] ?? '')));
}
unset($todo);

ob_start();
renderSiteHead();
$siteHeadHtml = ob_get_clean();

ob_start();
include 'includes/header.php';
$headerHtml = ob_get_clean();

ob_start();
include 'includes/footer.php';
$footerHtml = ob_get_clean();

view('front/todos.twig', [
    'status' => $status,
    'todo_stats' => $todoStats,
    'todos' => $todos,
    'site_head_html' => $siteHeadHtml,
    'header_html' => $headerHtml,
    'footer_html' => $footerHtml,
]);
