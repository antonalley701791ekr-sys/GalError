<?php
require_once 'includes/user_auth.php';
require_once 'includes/view.php';

$pdo = getDB();
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$tag = trim($_GET['tag'] ?? '');

$where = ["a.status = 'approved'"];
$params = [];

if ($tag) {
    $where[] = "FIND_IN_SET(?, a.tags)";
    $params[] = $tag;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

// 总数（JOIN users 确保只计入用户存在的文章）
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM articles a JOIN users u ON a.user_id = u.id {$whereClause}");
$stmt->execute($params);
$total = (int)($stmt->fetch()['total'] ?? 0);

$pagination = paginate($total, $page, $perPage);

// 文章列表
$sql = "SELECT a.*, u.username, u.avatar
        FROM articles a
        JOIN users u ON a.user_id = u.id
        {$whereClause}
        ORDER BY a.created_at DESC
        LIMIT {$pagination['offset']}, {$perPage}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$articles = $stmt->fetchAll();

// 批量获取文章浏览量
$artIds = array_column($articles, 'id');
$artViewCounts = getViewCountsBatch('article', $artIds);

// 批量获取文章评论数
$artCommentCounts = [];
if (!empty($artIds)) {
    $placeholders = implode(',', array_fill(0, count($artIds), '?'));
    $stmt = $pdo->prepare("SELECT content_id, COUNT(*) as cnt FROM comments WHERE content_type = 'article' AND status = 'active' AND content_id IN ($placeholders) GROUP BY content_id");
    $stmt->execute($artIds);
    foreach ($stmt->fetchAll() as $row) {
        $artCommentCounts[$row['content_id']] = (int)$row['cnt'];
    }
}

foreach ($articles as &$art) {
    $artId = (int)($art['id'] ?? 0);
    $artTags = array_filter(array_map('trim', explode(',', (string)($art['tags'] ?? ''))));

    $art['url'] = urlArticle($artId);
    $art['created_date'] = !empty($art['created_at']) ? date('Y-m-d', strtotime($art['created_at'])) : '';
    $art['view_count'] = (int)($artViewCounts[$artId] ?? 0);
    $art['comment_count'] = (int)($artCommentCounts[$artId] ?? 0);
    $art['tags'] = array_slice($artTags, 0, 3);

    $summary = mb_substr(strip_tags((string)($art['content'] ?? '')), 0, 120);
    $art['summary'] = trim($summary);
}
unset($art);

$tagQueryPrefix = $tag ? ('tag=' . urlencode($tag) . '&') : '';

ob_start();
renderSiteHead();
$siteHeadHtml = ob_get_clean();

ob_start();
include 'includes/header.php';
$headerHtml = ob_get_clean();

ob_start();
include 'includes/footer.php';
$footerHtml = ob_get_clean();

view('front/articles.twig', [
    'tag' => $tag,
    'total' => $total,
    'articles' => $articles,
    'pagination' => $pagination,
    'tag_query_prefix' => $tagQueryPrefix,
    'site_head_html' => $siteHeadHtml,
    'header_html' => $headerHtml,
    'footer_html' => $footerHtml,
]);
