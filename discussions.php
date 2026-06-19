<?php
require_once 'includes/user_auth.php';
require_once 'includes/view.php';

$pdo = getDB();
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$tag = trim($_GET['tag'] ?? '');

$where = ["d.status = 'active'"];
$params = [];

if ($tag) {
    $where[] = "FIND_IN_SET(?, d.tags)";
    $params[] = $tag;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM discussions d JOIN users u ON d.user_id = u.id {$whereClause}");
$stmt->execute($params);
$total = (int)$stmt->fetch()['total'];

$pagination = paginate($total, $page, $perPage);

$sql = "SELECT d.*, u.username, u.avatar
        FROM discussions d
        JOIN users u ON d.user_id = u.id
        {$whereClause}
        ORDER BY d.created_at DESC
        LIMIT {$pagination['offset']}, {$perPage}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$discussions = $stmt->fetchAll();

$discIds = array_column($discussions, 'id');
$discViewCounts = getViewCountsBatch('discussion', $discIds);

$discCommentCounts = [];
if (!empty($discIds)) {
    $placeholders = implode(',', array_fill(0, count($discIds), '?'));
    $stmt = $pdo->prepare("SELECT content_id, COUNT(*) as cnt FROM comments WHERE content_type = 'discussion' AND status = 'active' AND content_id IN ($placeholders) GROUP BY content_id");
    $stmt->execute($discIds);
    foreach ($stmt->fetchAll() as $row) {
        $discCommentCounts[$row['content_id']] = (int)$row['cnt'];
    }
}

$tagQueryPrefix = $tag ? ('tag=' . urlencode($tag) . '&') : '';

foreach ($discussions as &$disc) {
    $id = (int)$disc['id'];
    $tags = array_filter(array_map('trim', explode(',', (string)($disc['tags'] ?? ''))));
    $summary = mb_substr(strip_tags((string)($disc['content'] ?? '')), 0, 120);

    $disc['url'] = urlDiscussion($id);
    $disc['created_date'] = !empty($disc['created_at']) ? date('Y-m-d', strtotime($disc['created_at'])) : '';
    $disc['view_count'] = (int)($discViewCounts[$id] ?? 0);
    $disc['comment_count'] = (int)($discCommentCounts[$id] ?? 0);
    $disc['tags'] = array_slice($tags, 0, 3);
    $disc['summary'] = $summary;
}
unset($disc);

ob_start();
renderSiteHead();
$siteHeadHtml = ob_get_clean();

ob_start();
include 'includes/header.php';
$headerHtml = ob_get_clean();

ob_start();
include 'includes/footer.php';
$footerHtml = ob_get_clean();

view('front/discussions.twig', [
    'tag' => $tag,
    'total' => $total,
    'discussions' => $discussions,
    'pagination' => $pagination,
    'tag_query_prefix' => $tagQueryPrefix,
    'site_head_html' => $siteHeadHtml,
    'header_html' => $headerHtml,
    'footer_html' => $footerHtml,
]);
