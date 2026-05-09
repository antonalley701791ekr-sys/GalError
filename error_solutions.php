<?php
require_once 'includes/user_auth.php';
require_once 'includes/sanitizer.php';

$pdo = getDB();
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$categoryId = intval($_GET['category_id'] ?? 0);
$systemCategory = trim($_GET['system_category'] ?? '');

$systemCategoryOptions = [
    'windows' => 'Windows',
    'android_emulator' => '安卓模拟器',
    'console_handheld' => '主机掌机',
    'mobile_native' => '手机原生',
    'win_handheld' => 'Win掌机',
    'cloud_streaming' => '云/串流',
    'other' => '其他',
];

$categories = $pdo->query("SELECT id, name FROM error_categories ORDER BY sort_order ASC, id ASC")->fetchAll();
$validCategoryIds = array_map(function($c) { return (int)$c['id']; }, $categories);
if ($categoryId > 0 && !in_array($categoryId, $validCategoryIds, true)) {
    $categoryId = 0;
}

$where = ["e.status = 'approved'"];
$params = [];

if ($categoryId > 0) {
    $where[] = "e.category_id = ?";
    $params[] = $categoryId;
}

if ($systemCategory !== '' && isset($systemCategoryOptions[$systemCategory])) {
    $where[] = "e.system_category = ?";
    $params[] = $systemCategory;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM errors e {$whereClause}");
$countStmt->execute($params);
$total = (int)($countStmt->fetch()['total'] ?? 0);
$pagination = paginate($total, $page, $perPage);

$listSql = "
    SELECT e.*, g.title as game_title, c.name as category_name
    FROM errors e
    JOIN games g ON e.game_id = g.id
    JOIN error_categories c ON e.category_id = c.id
    {$whereClause}
    ORDER BY e.created_at DESC
    LIMIT {$pagination['offset']}, {$perPage}
";
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$errors = $listStmt->fetchAll();

$errorIds = array_column($errors, 'id');
$errorViews = getViewCountsBatch('error', $errorIds);

$errorCommentCounts = [];
if (!empty($errorIds)) {
    $placeholders = implode(',', array_fill(0, count($errorIds), '?'));
    $commentStmt = $pdo->prepare("SELECT content_id, COUNT(*) as cnt FROM comments WHERE content_type = 'error' AND status = 'active' AND content_id IN ($placeholders) GROUP BY content_id");
    $commentStmt->execute($errorIds);
    foreach ($commentStmt->fetchAll() as $row) {
        $errorCommentCounts[$row['content_id']] = (int)$row['cnt'];
    }
}

$queryParts = [];
if ($categoryId > 0) {
    $queryParts[] = 'category_id=' . urlencode((string)$categoryId);
}
if ($systemCategory !== '' && isset($systemCategoryOptions[$systemCategory])) {
    $queryParts[] = 'system_category=' . urlencode($systemCategory);
}
$queryPrefix = !empty($queryParts) ? (implode('&', $queryParts) . '&') : '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>报错/解决方案列表 - <?php echo h(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/user.css?v=<?php echo ASSETS_VER; ?>">
    <?php renderSiteHead(); ?>
</head>
<body class="has-fixed-nav">
    <?php include 'includes/header.php'; ?>

    <main class="main">
        <div class="container">
            <div class="mb-20" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                <div>
                    <h2>报错/解决方案列表</h2>
                    <p class="text-muted" style="margin-top:4px;font-size:0.85rem;">共 <?php echo $total; ?> 条记录</p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <a href="/" class="btn btn-secondary">返回首页</a>
                    <?php if (isUserLoggedIn()): ?>
                        <a href="/submit" class="btn">提交报错/解决方案</a>
                    <?php else: ?>
                        <a href="/login?redirect=<?php echo urlencode('/submit'); ?>" class="btn">提交报错/解决方案</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card" style="margin-bottom: 16px;">
                <div class="card-body" style="padding: 12px 16px;">
                    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                        <div>
                            <label class="form-label" style="margin-bottom:4px;font-size:0.82rem;">报错分类</label>
                            <select name="category_id" class="form-input" style="min-width:180px;">
                                <option value="">全部分类</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo (int)$category['id']; ?>" <?php echo $categoryId === (int)$category['id'] ? 'selected' : ''; ?>>
                                        <?php echo h($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" style="margin-bottom:4px;font-size:0.82rem;">系统分类</label>
                            <select name="system_category" class="form-input" style="min-width:180px;">
                                <option value="">全部系统</option>
                                <?php foreach ($systemCategoryOptions as $scKey => $scLabel): ?>
                                    <option value="<?php echo h($scKey); ?>" <?php echo $systemCategory === $scKey ? 'selected' : ''; ?>>
                                        <?php echo h($scLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="btn">筛选</button>
                            <a href="/error_solutions.php" class="btn btn-secondary">清除</a>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="card-body" style="padding: 0;">
                    <?php foreach ($errors as $error): ?>
                        <a class="error-card" href="<?php echo urlError($error['id']); ?>">
                            <h3 class="error-title"><?php echo h($error['title']); ?></h3>
                            <div class="error-meta">
                                <span>游戏：<?php echo hs($error['game_title']); ?></span>
                                <span>分类：<?php echo h($error['category_name']); ?></span>
                                <span>时间：<?php echo date('Y-m-d', strtotime($error['created_at'])); ?></span>
                                <span class="view-count-inline" title="浏览量">
                                    <svg class="view-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <?php echo $errorViews[$error['id']] ?? 0; ?>
                                </span>
                                <span class="comment-count-inline" title="评论数">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                    <?php echo $errorCommentCounts[$error['id']] ?? 0; ?>
                                </span>
                            </div>
                            <div class="error-content">
                                <div class="error-section">
                                    <h4>解决方案摘要：</h4>
                                    <?php if (!empty(trim($error['solution']))): ?>
                                        <?php $solutionSummary = preg_replace('/\s+/u', ' ', trim(strip_tags($error['solution']))); ?>
                                        <p class="error-solution-summary"><?php echo h(mb_substr($solutionSummary, 0, 100) . (mb_strlen($solutionSummary) > 100 ? '...' : '')); ?></p>
                                    <?php else: ?>
                                        <p class="text-muted">暂无解决方案，等待补充</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php if ($pagination['totalPages'] > 1): ?>
                    <div class="pagination">
                        <?php if ($pagination['hasPrev']): ?>
                            <a href="?<?php echo $queryPrefix; ?>page=<?php echo $pagination['page'] - 1; ?>">上一页</a>
                        <?php endif; ?>
                        <?php for ($i = max(1, $pagination['page'] - 2); $i <= min($pagination['totalPages'], $pagination['page'] + 2); $i++): ?>
                            <?php if ($i == $pagination['page']): ?>
                                <span class="current"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?<?php echo $queryPrefix; ?>page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($pagination['hasNext']): ?>
                            <a href="?<?php echo $queryPrefix; ?>page=<?php echo $pagination['page'] + 1; ?>">下一页</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="card">
                    <div class="card-body text-center empty-state">
                        <p>暂无报错/解决方案</p>
                        <p class="text-muted">欢迎提交第一条记录</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
