<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/view.php';

checkLogin();

$pdo = getDB();

$stats = [
    'total_games' => $pdo->query("SELECT COUNT(*) as count FROM games WHERE status = 'approved'")->fetch()['count'],
    'total_errors' => $pdo->query("SELECT COUNT(*) as count FROM errors")->fetch()['count'],
    'pending_errors' => $pdo->query("SELECT COUNT(*) as count FROM errors WHERE status = 'pending'")->fetch()['count'],
    'approved_errors' => $pdo->query("SELECT COUNT(*) as count FROM errors WHERE status = 'approved'")->fetch()['count'],
    'pending_games' => $pdo->query("SELECT COUNT(*) as count FROM games WHERE status = 'pending'")->fetch()['count'],
    'pending_articles' => $pdo->query("SELECT COUNT(*) as count FROM articles WHERE status = 'pending'")->fetch()['count'],
    'total_articles' => $pdo->query("SELECT COUNT(*) as count FROM articles WHERE status = 'approved'")->fetch()['count'],
    'pending_solutions' => $pdo->query("SELECT COUNT(*) as count FROM error_solutions WHERE status = 'pending'")->fetch()['count'],
];

$recentErrors = $pdo->query("
    SELECT e.*, g.title as game_title, c.name as category_name 
    FROM errors e 
    JOIN games g ON e.game_id = g.id 
    JOIN error_categories c ON e.category_id = c.id 
    WHERE e.status = 'pending' 
    ORDER BY e.created_at DESC 
    LIMIT 5
")->fetchAll();

ob_start();
renderAdminHeadScript();
$adminHeadHtml = ob_get_clean();

ob_start();
include '../includes/header.php';
$headerHtml = ob_get_clean();

ob_start();
renderAdminSidebar('index.php');
$sidebarHtml = ob_get_clean();

ob_start();
renderAdminFooterScripts();
$footerScriptsHtml = ob_get_clean();

view('admin/index.twig', [
    'page_title' => '控制台',
    'stats' => $stats,
    'recentErrors' => $recentErrors,
    'admin_css_mtime' => @filemtime(BASE_PATH . '/assets/css/style.css') ?: time(),
    'admin_head_html' => $adminHeadHtml,
    'header_html' => $headerHtml,
    'sidebar_html' => $sidebarHtml,
    'footer_scripts_html' => $footerScriptsHtml,
]);
