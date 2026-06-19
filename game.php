<?php
require_once 'includes/user_auth.php';
require_once 'includes/image_utils.php';
require_once 'includes/sanitizer.php';
require_once 'includes/auth.php';
require_once 'includes/view.php';

$fromAdmin = isset($_GET['from_admin']) && $_GET['from_admin'] === '1';
if ($fromAdmin) {
    checkLogin();
    requirePermission('games', 'view');
}

$pdo = getDB();
$gameId = intval($_GET['id'] ?? 0);
if (!$gameId) {
    header('Location: /');
    exit;
}

if ($fromAdmin) {
    $stmt = $pdo->prepare("SELECT g.*, u.username as submitter_name FROM games g LEFT JOIN users u ON g.user_id = u.id WHERE g.id = ?");
} else {
    $stmt = $pdo->prepare("SELECT g.*, u.username as submitter_name FROM games g LEFT JOIN users u ON g.user_id = u.id WHERE g.id = ? AND g.status = 'approved'");
}
$stmt->execute([$gameId]);
$game = $stmt->fetch();
if (!$game) {
    header('Location: /');
    exit;
}

$gameViewCounts = getViewCount('game', $gameId);

$stmt = $pdo->prepare("SELECT r.*, u.username as submitter_name FROM game_revisions r LEFT JOIN users u ON r.user_id = u.id WHERE r.game_id = ? AND r.status = 'approved' ORDER BY r.created_at ASC");
$stmt->execute([$gameId]);
$gameRevisions = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT e.*, c.name as category_name, es.solution AS solution_text FROM errors e JOIN error_categories c ON e.category_id = c.id LEFT JOIN error_solutions es ON es.error_id = e.id AND es.is_primary = 1 AND es.status = 'approved' WHERE e.game_id = ? AND e.status = 'approved' ORDER BY e.created_at DESC");
$stmt->execute([$gameId]);
$errors = $stmt->fetchAll();

$errorIds = array_column($errors, 'id');
$errorViewCounts = getViewCountsBatch('error', $errorIds);
$errorCommentCounts = [];
if (!empty($errorIds)) {
    $placeholders = implode(',', array_fill(0, count($errorIds), '?'));
    $stmt = $pdo->prepare("SELECT content_id, COUNT(*) as cnt FROM comments WHERE content_type = 'error' AND status = 'active' AND content_id IN ($placeholders) GROUP BY content_id");
    $stmt->execute($errorIds);
    foreach ($stmt->fetchAll() as $row) {
        $errorCommentCounts[$row['content_id']] = (int)$row['cnt'];
    }
}

$game['cover_url'] = getCoverUrl($game);
$game['created_date'] = !empty($game['created_at']) ? date('Y-m-d', strtotime($game['created_at'])) : '';
$game['view_user'] = (int)($gameViewCounts['user_views'] ?? 0);
$game['view_guest'] = (int)($gameViewCounts['guest_views'] ?? 0);

$fieldLabels = [
    'vndb_id' => 'VNDB', 'title' => '游戏标题', 'title_jp' => '日文名', 'romaji' => '罗马音',
    'aliases' => '别名', 'developer' => '开发商', 'release_date' => '发售日', 'platforms' => '平台',
];
foreach ($gameRevisions as &$rev) {
    $rev['created_text'] = !empty($rev['created_at']) ? date('Y-m-d H:i', strtotime($rev['created_at'])) : '';
    $oldD = json_decode($rev['old_data'] ?? '', true) ?: [];
    $newD = json_decode($rev['new_data'] ?? '', true) ?: [];
    $diffs = [];
    foreach ($fieldLabels as $field => $label) {
        $oldVal = (string)($oldD[$field] ?? '');
        $newVal = (string)($newD[$field] ?? '');
        if ($oldVal !== $newVal) {
            $mode = ($oldVal === '' && $newVal !== '') ? 'added' : (($oldVal !== '' && $newVal === '') ? 'removed' : 'changed');
            $diffs[] = ['label' => $label, 'old' => $oldVal, 'new' => $newVal, 'mode' => $mode];
        }
    }
    $rev['diffs'] = $diffs;
}
unset($rev);

foreach ($errors as &$error) {
    $id = (int)$error['id'];
    $error['url'] = urlError($id);
    $error['created_date'] = !empty($error['created_at']) ? date('Y-m-d', strtotime($error['created_at'])) : '';
    $error['view_count'] = (int)($errorViewCounts[$id] ?? 0);
    $error['comment_count'] = (int)($errorCommentCounts[$id] ?? 0);
    $s = trim((string)($error['solution_text'] ?? ''));
    $error['solution_short'] = $s !== '' ? mb_substr($s, 0, 30) . (mb_strlen($s) > 30 ? '...' : '') : '';
}
unset($error);

ob_start(); renderSiteHead(); $siteHeadHtml = ob_get_clean();
ob_start(); include 'includes/header.php'; $headerHtml = ob_get_clean();
ob_start(); renderAnnouncement(); $announcementHtml = ob_get_clean();
ob_start(); include 'includes/footer.php'; $footerHtml = ob_get_clean();

$adminSidebarHtml = '';
$adminFooterScriptsHtml = '';
if ($fromAdmin) {
    ob_start(); renderAdminSidebar('games.php'); $adminSidebarHtml = ob_get_clean();
    ob_start(); renderAdminFooterScripts(); $adminFooterScriptsHtml = ob_get_clean();
}

view('front/game.twig', [
    'from_admin' => $fromAdmin,
    'game' => $game,
    'errors' => $errors,
    'game_revisions' => $gameRevisions,
    'csrf' => csrf_token('default'),
    'site_head_html' => $siteHeadHtml,
    'header_html' => $headerHtml,
    'announcement_html' => $announcementHtml,
    'footer_html' => $footerHtml,
    'admin_sidebar_html' => $adminSidebarHtml,
    'admin_footer_scripts_html' => $adminFooterScriptsHtml,
]);
