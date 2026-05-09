<?php
require_once 'includes/user_auth.php';

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
$total = $stmt->fetch()['total'];

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
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>文章列表 - <?php echo h(SITE_NAME); ?></title>
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
                    <h2>文章列表</h2>
                    <?php if ($tag): ?>
                        <p class="text-muted" style="margin-top:6px;">
                            标签筛选：<strong style="color:var(--accent-purple);"><?php echo h($tag); ?></strong>
                            <a href="/articles" style="margin-left:8px;font-size:0.85rem;">清除</a>
                        </p>
                    <?php endif; ?>
                    <p class="text-muted" style="margin-top:4px;font-size:0.85rem;">共 <?php echo $total; ?> 篇文章</p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <a href="/" class="btn btn-secondary">返回首页</a>
                    <?php if (isUserLoggedIn()): ?>
                        <a href="/submit_article" class="btn">提交文章</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($articles)): ?>
                <div class="article-list-grid">
                    <?php foreach ($articles as $art): ?>
                        <?php $artTags = array_filter(array_map('trim', explode(',', $art['tags']))); ?>
                        <a class="article-card" href="<?php echo urlArticle($art['id']); ?>">
                            <h3 class="article-list-title">
                                <?php echo h($art['title']); ?>
                            </h3>
                            <div class="article-list-meta">
                                <span class="article-author-inline">
                                    <?php echo h($art['username']); ?>
                                </span>
                                <span><?php echo date('Y-m-d', strtotime($art['created_at'])); ?></span>
                                <span class="view-count-inline" title="浏览量">
                                    <svg class="view-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <?php echo $artViewCounts[$art['id']] ?? 0; ?>
                                </span>
                                <span class="comment-count-inline" title="评论数">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                    <?php echo $artCommentCounts[$art['id']] ?? 0; ?>
                                </span>
                            </div>
                            <?php if (!empty($artTags)): ?>
                                <div class="article-list-tags">
                                    <?php foreach (array_slice($artTags, 0, 3) as $t): ?>
                                        <span class="article-tag-sm"><?php echo h($t); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <p class="article-list-summary">
                                <?php echo h(mb_substr(strip_tags($art['content']), 0, 120)); ?>...
                            </p>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- 分页 -->
                <?php if ($pagination['totalPages'] > 1): ?>
                    <div class="pagination">
                        <?php if ($pagination['hasPrev']): ?>
                            <a href="?<?php echo $tag ? 'tag=' . urlencode($tag) . '&' : ''; ?>page=<?php echo $pagination['page'] - 1; ?>">上一页</a>
                        <?php endif; ?>
                        <?php for ($i = max(1, $pagination['page'] - 2); $i <= min($pagination['totalPages'], $pagination['page'] + 2); $i++): ?>
                            <?php if ($i == $pagination['page']): ?>
                                <span class="current"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?<?php echo $tag ? 'tag=' . urlencode($tag) . '&' : ''; ?>page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($pagination['hasNext']): ?>
                            <a href="?<?php echo $tag ? 'tag=' . urlencode($tag) . '&' : ''; ?>page=<?php echo $pagination['page'] + 1; ?>">下一页</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="card">
                    <div class="card-body text-center empty-state">
                        <p>暂无文章</p>
                        <?php if (isUserLoggedIn()): ?>
                            <p class="text-muted">成为第一个分享的人吧！</p>
                            <a href="/submit_article" class="btn" style="margin-top:12px;">提交文章</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
