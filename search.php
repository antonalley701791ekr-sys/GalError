<?php
require_once 'includes/user_auth.php';
require_once 'includes/sanitizer.php';
require_once 'includes/view.php';

$pdo = getDB();
$query = trim($_GET['q'] ?? '');
$searchType = $_GET['type'] ?? 'article';
$category = intval($_GET['category'] ?? 0);
$systemCategory = trim($_GET['system_category'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;

$systemCategoryOptions = [
    'windows' => 'Windows',
    'android_emulator' => '安卓模拟器',
    'console_handheld' => '主机掌机',
    'mobile_native' => '手机原生',
    'win_handheld' => 'Win掌机',
    'cloud_streaming' => '云/串流',
    'other' => '其他',
];

$results = [];
$total = 0;
$validTypes = ['error', 'game', 'article', 'discussion'];
if (!in_array($searchType, $validTypes, true)) {
    $searchType = 'article';
}

$hasFilters = ($query !== '' || $category > 0 || ($searchType === 'error' && $systemCategory !== ''));
if ($hasFilters) {
    $searchTerm = "%{$query}%";

    if ($searchType === 'game') {
        $where = [];
        $params = [];
        if ($query) {
            $where[] = "(g.title LIKE ? OR g.title_jp LIKE ? OR g.romaji LIKE ? OR g.aliases LIKE ? OR g.vndb_id LIKE ?)";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        }
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : 'WHERE 1=1';
        $whereClause .= " AND g.status = 'approved'";

        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM games g {$whereClause}");
        $stmt->execute($params);
        $total = (int)$stmt->fetch()['total'];

        $pagination = paginate($total, $page, $perPage);
        $stmt = $pdo->prepare("SELECT g.* FROM games g {$whereClause} ORDER BY g.created_at DESC LIMIT {$pagination['offset']}, {$perPage}");
        $stmt->execute($params);
        $results = $stmt->fetchAll();
    } elseif ($searchType === 'article') {
        $where = [];
        $params = [];
        if ($query) {
            $where[] = "(a.title LIKE ? OR a.content LIKE ? OR FIND_IN_SET(?, a.tags) OR a.tags LIKE ?)";
            $params = array_merge($params, [$searchTerm, $searchTerm, $query, $searchTerm]);
        }
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : 'WHERE 1=1';
        $whereClause .= " AND a.status = 'approved'";

        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM articles a JOIN users u ON a.user_id = u.id {$whereClause}");
        $stmt->execute($params);
        $total = (int)$stmt->fetch()['total'];

        $pagination = paginate($total, $page, $perPage);
        $stmt = $pdo->prepare("SELECT a.*, u.username FROM articles a JOIN users u ON a.user_id = u.id {$whereClause} ORDER BY a.created_at DESC LIMIT {$pagination['offset']}, {$perPage}");
        $stmt->execute($params);
        $results = $stmt->fetchAll();
    } elseif ($searchType === 'discussion') {
        $where = [];
        $params = [];
        if ($query) {
            $where[] = "(d.title LIKE ? OR d.content LIKE ? OR FIND_IN_SET(?, d.tags) OR d.tags LIKE ?)";
            $params = array_merge($params, [$searchTerm, $searchTerm, $query, $searchTerm]);
        }
        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : 'WHERE 1=1';
        $whereClause .= " AND d.status = 'active'";

        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM discussions d JOIN users u ON d.user_id = u.id {$whereClause}");
        $stmt->execute($params);
        $total = (int)$stmt->fetch()['total'];

        $pagination = paginate($total, $page, $perPage);
        $stmt = $pdo->prepare("SELECT d.*, u.username FROM discussions d JOIN users u ON d.user_id = u.id {$whereClause} ORDER BY d.created_at DESC LIMIT {$pagination['offset']}, {$perPage}");
        $stmt->execute($params);
        $results = $stmt->fetchAll();
    } else {
        $where = [];
        $params = [];
        if ($query) {
            $where[] = "(g.title LIKE ? OR g.title_jp LIKE ? OR g.romaji LIKE ? OR g.aliases LIKE ? OR g.vndb_id LIKE ? OR e.title LIKE ? OR e.phenomenon LIKE ? OR es.solution LIKE ?)";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        }
        if ($category) {
            $where[] = 'e.category_id = ?';
            $params[] = $category;
        }
        if ($systemCategory !== '' && isset($systemCategoryOptions[$systemCategory])) {
            $where[] = 'e.system_category = ?';
            $params[] = $systemCategory;
        }
        $where[] = "e.status = 'approved'";

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT e.id) as total FROM errors e JOIN games g ON e.game_id = g.id LEFT JOIN error_solutions es ON es.error_id = e.id AND es.is_primary = 1 AND es.status = 'approved' JOIN error_categories c ON e.category_id = c.id {$whereClause}");
        $stmt->execute($params);
        $total = (int)$stmt->fetch()['total'];

        $pagination = paginate($total, $page, $perPage);
        $stmt = $pdo->prepare("SELECT DISTINCT e.*, g.title as game_title, g.vndb_id, c.name as category_name, es.solution as solution_text FROM errors e JOIN games g ON e.game_id = g.id JOIN error_categories c ON e.category_id = c.id LEFT JOIN error_solutions es ON es.error_id = e.id AND es.is_primary = 1 AND es.status = 'approved' {$whereClause} ORDER BY e.created_at DESC LIMIT {$pagination['offset']}, {$perPage}");
        $stmt->execute($params);
        $results = $stmt->fetchAll();
    }
} else {
    $pagination = paginate(0, $page, $perPage);
}

$categories = $pdo->query('SELECT * FROM error_categories ORDER BY sort_order ASC')->fetchAll();

$searchResultViews = [];
if (!empty($results)) {
    if ($searchType === 'game') {
        $searchResultViews = getViewCountsBatch('game', array_column($results, 'id'));
    } elseif ($searchType === 'article') {
        $searchResultViews = getViewCountsBatch('article', array_column($results, 'id'));
    } elseif ($searchType === 'discussion') {
        $searchResultViews = getViewCountsBatch('discussion', array_column($results, 'id'));
    } else {
        $searchResultViews = getViewCountsBatch('error', array_column($results, 'id'));
    }
}

$searchDiscCommentCounts = [];
if ($searchType === 'discussion' && !empty($results)) {
    $discIds = array_column($results, 'id');
    $placeholders = implode(',', array_fill(0, count($discIds), '?'));
    $stmt = $pdo->prepare("SELECT content_id, COUNT(*) as cnt FROM comments WHERE content_type = 'discussion' AND status = 'active' AND content_id IN ($placeholders) GROUP BY content_id");
    $stmt->execute($discIds);
    foreach ($stmt->fetchAll() as $row) {
        $searchDiscCommentCounts[$row['content_id']] = (int)$row['cnt'];
    }
}

$typeLabels = ['article' => '文章', 'game' => '游戏', 'error' => '报错内容', 'discussion' => '话题'];
$currentCategoryName = '';
foreach ($categories as $c) {
    if ((int)$c['id'] === $category) {
        $currentCategoryName = $c['name'];
        break;
    }
}
$currentSystemCategoryName = ($searchType === 'error' && $systemCategory !== '' && isset($systemCategoryOptions[$systemCategory])) ? $systemCategoryOptions[$systemCategory] : '';

$pagerParams = 'type=' . urlencode($searchType);
if ($query) $pagerParams .= '&q=' . urlencode($query);
if ($category) $pagerParams .= '&category=' . $category;
if ($searchType === 'error' && $systemCategory !== '' && isset($systemCategoryOptions[$systemCategory])) $pagerParams .= '&system_category=' . urlencode($systemCategory);

foreach ($results as &$item) {
    $id = (int)$item['id'];
    $item['view_count'] = (int)($searchResultViews[$id] ?? 0);

    if ($searchType === 'game') {
        $item['url'] = urlGame($id);
        $coverUrl = '';
        if (!empty($item['cover_image']) && file_exists(BASE_PATH . $item['cover_image'])) {
            $coverUrl = $item['cover_image'];
        } elseif (!empty($item['vndb_cover_url'])) {
            $coverUrl = '/image_proxy?url=' . urlencode($item['vndb_cover_url']);
        }
        $item['cover_url'] = $coverUrl;
    } elseif ($searchType === 'article' || $searchType === 'discussion') {
        $item['url'] = $searchType === 'article' ? urlArticle($id) : urlDiscussion($id);
        $item['created_date'] = !empty($item['created_at']) ? date('Y-m-d', strtotime($item['created_at'])) : '';
        $tags = array_filter(array_map('trim', explode(',', (string)($item['tags'] ?? ''))));
        $outTags = [];
        foreach (array_slice($tags, 0, 5) as $t) {
            $outTags[] = ['value' => $t, 'matched' => ($query && (stripos($t, $query) !== false || $t === $query))];
        }
        $item['tags'] = $outTags;
        $item['summary'] = mb_substr(strip_tags((string)($item['content'] ?? '')), 0, 150);
        if ($searchType === 'discussion') {
            $item['comment_count'] = (int)($searchDiscCommentCounts[$id] ?? 0);
        }
    } else {
        $item['url'] = urlError($id);
        $item['created_date'] = !empty($item['created_at']) ? date('Y-m-d', strtotime($item['created_at'])) : '';
        $item['category_matched'] = ($query && stripos((string)$item['category_name'], $query) !== false);
        $phenomenon = (string)($item['phenomenon'] ?? '');
        $solution = (string)($item['solution'] ?? '');
        $item['phenomenon_short'] = $phenomenon ? mb_substr($phenomenon, 0, 150) . (mb_strlen($phenomenon) > 150 ? '...' : '') : '';
        $item['solution_short'] = mb_substr($solution, 0, 200) . (mb_strlen($solution) > 200 ? '...' : '');
    }
}
unset($item);

ob_start(); renderSiteHead(); $siteHeadHtml = ob_get_clean();
ob_start(); include 'includes/header.php'; $headerHtml = ob_get_clean();
ob_start(); renderAnnouncement(); $announcementHtml = ob_get_clean();
ob_start(); include 'includes/footer.php'; $footerHtml = ob_get_clean();

view('front/search.twig', [
    'query' => $query,
    'search_type' => $searchType,
    'category' => $category,
    'system_category' => $systemCategory,
    'system_category_options' => $systemCategoryOptions,
    'categories' => $categories,
    'results' => $results,
    'total' => $total,
    'pagination' => $pagination,
    'type_labels' => $typeLabels,
    'current_category_name' => $currentCategoryName,
    'current_system_category_name' => $currentSystemCategoryName,
    'has_filters' => $hasFilters,
    'pager_params' => $pagerParams,
    'site_head_html' => $siteHeadHtml,
    'header_html' => $headerHtml,
    'announcement_html' => $announcementHtml,
    'footer_html' => $footerHtml,
]);
