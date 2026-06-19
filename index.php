<?php
require_once 'includes/user_auth.php';
require_once 'includes/image_utils.php';
require_once 'includes/sanitizer.php';
require_once 'includes/view.php';

$pdo = getDB();
$stmt = $pdo->query("SELECT * FROM games WHERE status = 'approved' ORDER BY created_at DESC LIMIT 12");
$games = $stmt->fetchAll();

$stmt = $pdo->query("SELECT * FROM error_categories ORDER BY sort_order ASC");
$categories = $stmt->fetchAll();

$stmt = $pdo->query("SELECT e.*, g.title as game_title, c.name as category_name, es.solution AS solution_text FROM errors e JOIN games g ON e.game_id = g.id JOIN error_categories c ON e.category_id = c.id LEFT JOIN error_solutions es ON es.error_id = e.id AND es.is_primary = 1 AND es.status = 'approved' WHERE e.status = 'approved' ORDER BY e.created_at DESC LIMIT 6");
$recentErrors = $stmt->fetchAll();

$systemCategoryOptions = [
    'windows' => 'Windows',
    'android_emulator' => '安卓模拟器',
    'console_handheld' => '主机掌机',
    'mobile_native' => '手机原生',
    'win_handheld' => 'Win掌机',
    'cloud_streaming' => '云/串流',
    'other' => '其他',
];

$stmt = $pdo->query("SELECT a.*, u.username FROM articles a JOIN users u ON a.user_id = u.id WHERE a.status = 'approved' ORDER BY a.created_at DESC LIMIT 10");
$recentArticles = $stmt->fetchAll();

$stmt = $pdo->query("SELECT d.*, u.username FROM discussions d JOIN users u ON d.user_id = u.id WHERE d.status = 'active' ORDER BY d.created_at DESC LIMIT 10");
$recentDiscussions = $stmt->fetchAll();

$articleIds = array_column($recentArticles, 'id');
$articleViews = getViewCountsBatch('article', $articleIds);

$articleCommentCounts = [];
if (!empty($articleIds)) {
    $placeholders = implode(',', array_fill(0, count($articleIds), '?'));
    $stmt = $pdo->prepare("SELECT content_id, COUNT(*) as cnt FROM comments WHERE content_type = 'article' AND status = 'active' AND content_id IN ($placeholders) GROUP BY content_id");
    $stmt->execute($articleIds);
    foreach ($stmt->fetchAll() as $row) {
        $articleCommentCounts[$row['content_id']] = (int)$row['cnt'];
    }
}

$gameIds = array_column($games, 'id');
$gameViews = getViewCountsBatch('game', $gameIds);

$gameErrorCounts = [];
if (!empty($gameIds)) {
    $placeholders = implode(',', array_fill(0, count($gameIds), '?'));
    $stmt = $pdo->prepare("SELECT game_id, COUNT(*) as cnt FROM errors WHERE status = 'approved' AND game_id IN ($placeholders) GROUP BY game_id");
    $stmt->execute($gameIds);
    foreach ($stmt->fetchAll() as $row) {
        $gameErrorCounts[$row['game_id']] = (int)$row['cnt'];
    }
}

$errorIds = array_column($recentErrors, 'id');
$errorViews = getViewCountsBatch('error', $errorIds);

$errorCommentCounts = [];
if (!empty($errorIds)) {
    $placeholders = implode(',', array_fill(0, count($errorIds), '?'));
    $stmt = $pdo->prepare("SELECT content_id, COUNT(*) as cnt FROM comments WHERE content_type = 'error' AND status = 'active' AND content_id IN ($placeholders) GROUP BY content_id");
    $stmt->execute($errorIds);
    foreach ($stmt->fetchAll() as $row) {
        $errorCommentCounts[$row['content_id']] = (int)$row['cnt'];
    }
}

$discIds = array_column($recentDiscussions, 'id');
$discViews = getViewCountsBatch('discussion', $discIds);

$discCommentCounts = [];
if (!empty($discIds)) {
    $placeholders = implode(',', array_fill(0, count($discIds), '?'));
    $stmt = $pdo->prepare("SELECT content_id, COUNT(*) as cnt FROM comments WHERE content_type = 'discussion' AND status = 'active' AND content_id IN ($placeholders) GROUP BY content_id");
    $stmt->execute($discIds);
    foreach ($stmt->fetchAll() as $row) {
        $discCommentCounts[$row['content_id']] = (int)$row['cnt'];
    }
}

foreach ($recentArticles as &$art) {
    $id = (int)$art['id'];
    $tags = array_filter(array_map('trim', explode(',', (string)($art['tags'] ?? ''))));
    $art['url'] = urlArticle($id);
    $art['title_short'] = mb_substr((string)$art['title'], 0, 50) . (mb_strlen((string)$art['title']) > 50 ? '...' : '');
    $art['created_date'] = !empty($art['created_at']) ? date('Y-m-d', strtotime($art['created_at'])) : '';
    $art['view_count'] = (int)($articleViews[$id] ?? 0);
    $art['comment_count'] = (int)($articleCommentCounts[$id] ?? 0);
    $art['tags'] = array_slice($tags, 0, 3);
    $art['summary'] = mb_substr(strip_tags((string)($art['content'] ?? '')), 0, 50);
}
unset($art);

foreach ($games as &$game) {
    $id = (int)$game['id'];
    $game['url'] = urlGame($id);
    $game['cover_url'] = getCoverUrl($game);
    $game['view_count'] = (int)($gameViews[$id] ?? 0);
    $game['error_count'] = (int)($gameErrorCounts[$id] ?? 0);
}
unset($game);

foreach ($recentErrors as &$error) {
    $id = (int)$error['id'];
    $error['url'] = urlError($id);
    $error['created_date'] = !empty($error['created_at']) ? date('Y-m-d', strtotime($error['created_at'])) : '';
    $error['view_count'] = (int)($errorViews[$id] ?? 0);
    $error['comment_count'] = (int)($errorCommentCounts[$id] ?? 0);
    $error['system_category_name'] = (!empty($error['system_category']) && isset($systemCategoryOptions[$error['system_category']])) ? $systemCategoryOptions[$error['system_category']] : '';
    $solution = trim((string)($error['solution_text'] ?? ''));
    if ($solution !== '') {
        $solutionSummary = preg_replace('/\s+/u', ' ', trim(strip_tags($solution)));
        $error['solution_summary'] = mb_substr($solutionSummary, 0, 70) . (mb_strlen($solutionSummary) > 70 ? '...' : '');
    } else {
        $error['solution_summary'] = '';
    }
}
unset($error);

foreach ($recentDiscussions as &$disc) {
    $id = (int)$disc['id'];
    $tags = array_filter(array_map('trim', explode(',', (string)($disc['tags'] ?? ''))));
    $disc['url'] = urlDiscussion($id);
    $disc['title_short'] = mb_substr((string)$disc['title'], 0, 50) . (mb_strlen((string)$disc['title']) > 50 ? '...' : '');
    $disc['created_date'] = !empty($disc['created_at']) ? date('Y-m-d', strtotime($disc['created_at'])) : '';
    $disc['view_count'] = (int)($discViews[$id] ?? 0);
    $disc['comment_count'] = (int)($discCommentCounts[$id] ?? 0);
    $disc['tags'] = array_slice($tags, 0, 3);
    $disc['summary'] = mb_substr(strip_tags((string)($disc['content'] ?? '')), 0, 50);
}
unset($disc);

ob_start();
renderSiteHead();
$siteHeadHtml = ob_get_clean();

ob_start();
include __DIR__ . '/includes/header.php';
$headerHtml = ob_get_clean();

ob_start();
renderAnnouncement();
$announcementHtml = ob_get_clean();

ob_start();
renderDocumentCarousel();
$documentCarouselHtml = ob_get_clean();

ob_start();
include __DIR__ . '/includes/footer.php';
$footerHtml = ob_get_clean();

view('front/index.twig', [
    'page_title' => '首页',
    'categories' => $categories,
    'system_category_options' => $systemCategoryOptions,
    'games' => $games,
    'recent_errors' => $recentErrors,
    'recent_articles' => $recentArticles,
    'recent_discussions' => $recentDiscussions,
    'site_head_html' => $siteHeadHtml,
    'header_html' => $headerHtml,
    'announcement_html' => $announcementHtml,
    'document_carousel_html' => $documentCarouselHtml,
    'footer_html' => $footerHtml,
]);
