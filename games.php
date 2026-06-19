<?php
require_once 'includes/user_auth.php';
require_once 'includes/image_utils.php';
require_once 'includes/sanitizer.php';
require_once 'includes/view.php';

$pdo = getDB();
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 24;

// 总数
$stmt = $pdo->query("SELECT COUNT(*) as total FROM games WHERE status = 'approved'");
$total = (int)($stmt->fetch()['total'] ?? 0);

$pagination = paginate($total, $page, $perPage);

// 游戏列表
$sql = "SELECT * FROM games WHERE status = 'approved' ORDER BY created_at DESC LIMIT {$pagination['offset']}, {$perPage}";
$stmt = $pdo->query($sql);
$games = $stmt->fetchAll();

// 批量获取浏览量
$gameIds = array_column($games, 'id');
$gameViewCounts = getViewCountsBatch('game', $gameIds);

$gameErrorCounts = [];
if (!empty($gameIds)) {
    $placeholders = implode(',', array_fill(0, count($gameIds), '?'));
    $stmt = $pdo->prepare("SELECT game_id, COUNT(*) as cnt FROM errors WHERE status = 'approved' AND game_id IN ($placeholders) GROUP BY game_id");
    $stmt->execute($gameIds);
    foreach ($stmt->fetchAll() as $row) {
        $gameErrorCounts[$row['game_id']] = (int)$row['cnt'];
    }
}

foreach ($games as &$game) {
    $gameId = (int)($game['id'] ?? 0);
    $game['url'] = urlGame($gameId);
    $game['cover_url'] = getCoverUrl($game) ?: '';
    $game['view_count'] = (int)($gameViewCounts[$gameId] ?? 0);
    $game['error_count'] = (int)($gameErrorCounts[$gameId] ?? 0);
}
unset($game);

ob_start();
renderSiteHead();
$siteHeadHtml = ob_get_clean();

ob_start();
include 'includes/header.php';
$headerHtml = ob_get_clean();

ob_start();
include 'includes/footer.php';
$footerHtml = ob_get_clean();

view('front/games.twig', [
    'total' => $total,
    'games' => $games,
    'pagination' => $pagination,
    'site_head_html' => $siteHeadHtml,
    'header_html' => $headerHtml,
    'footer_html' => $footerHtml,
]);
