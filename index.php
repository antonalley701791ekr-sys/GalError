<?php
require_once 'includes/user_auth.php';
require_once 'includes/image_utils.php';
require_once 'includes/sanitizer.php';

// 获取最新游戏（仅已审核通过）
$pdo = getDB();
$stmt = $pdo->query("SELECT * FROM games WHERE status = 'approved' ORDER BY created_at DESC LIMIT 12");
$games = $stmt->fetchAll();

// 获取报错分类
$stmt = $pdo->query("SELECT * FROM error_categories ORDER BY sort_order ASC");
$categories = $stmt->fetchAll();

// 获取最新已审核的报错
$stmt = $pdo->query("
    SELECT e.*, g.title as game_title, c.name as category_name 
    FROM errors e 
    JOIN games g ON e.game_id = g.id 
    JOIN error_categories c ON e.category_id = c.id 
    WHERE e.status = 'approved' 
    ORDER BY e.created_at DESC 
    LIMIT 6
");
$recentErrors = $stmt->fetchAll();

// 获取最新审核通过的文章（相关文章栏目）
$stmt = $pdo->query("
    SELECT a.*, u.username 
    FROM articles a 
    JOIN users u ON a.user_id = u.id 
    WHERE a.status = 'approved' 
    ORDER BY a.created_at DESC 
    LIMIT 10
");
$recentArticles = $stmt->fetchAll();

// 获取最新讨论区话题
$stmt = $pdo->query("
    SELECT d.*, u.username
    FROM discussions d
    JOIN users u ON d.user_id = u.id
    WHERE d.status = 'active'
    ORDER BY d.created_at DESC
    LIMIT 10
");
$recentDiscussions = $stmt->fetchAll();

// 批量获取浏览量
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

// 批量获取讨论区评论数
$discCommentCounts = [];
if (!empty($discIds)) {
    $placeholders = implode(',', array_fill(0, count($discIds), '?'));
    $stmt = $pdo->prepare("SELECT content_id, COUNT(*) as cnt FROM comments WHERE content_type = 'discussion' AND status = 'active' AND content_id IN ($placeholders) GROUP BY content_id");
    $stmt->execute($discIds);
    foreach ($stmt->fetchAll() as $row) {
        $discCommentCounts[$row['content_id']] = (int)$row['cnt'];
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GalError - <?php echo h(SITE_NAME); ?></title>
    <meta name="description" content="GalError（galerror.top）专注 Galgame 报错解决方案，提供游戏报错排查、修复教程、经验文章与讨论区。">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/user.css?v=<?php echo ASSETS_VER; ?>">
    <?php renderSiteHead(); ?>
</head>
<body class="has-fixed-nav">
    <?php include 'includes/header.php'; ?>

    <!-- 搜索框 -->
    <section class="search-box">
        <div class="container">
            <form class="search-form search-form-tabbed" method="get" action="/search">
                <div class="search-tabs">
                    <label class="search-tab">
                        <input type="radio" name="type" value="article" checked>
                        <span>文章</span>
                    </label>
                    <label class="search-tab">
                        <input type="radio" name="type" value="game">
                        <span>游戏</span>
                    </label>
                    <label class="search-tab">
                        <input type="radio" name="type" value="error">
                        <span>报错内容</span>
                    </label>
                    <label class="search-tab">
                        <input type="radio" name="type" value="discussion">
                        <span>话题</span>
                    </label>
                </div>
                <div class="search-input-row">
                    <input type="text" name="q" class="search-input" placeholder="搜索游戏名、报错内容、VNDB编号..." required>
                    <button type="submit" class="search-btn">搜索</button>
                </div>
            </form>
        </div>
    </section>

    <?php renderAnnouncement(); ?>

    <?php renderDocumentCarousel(); ?>

    <!-- 主要内容 -->
    <main class="main">
        <div class="container">
            <!-- 常见报错分类 -->
            <section class="mb-20">
                <h2 class="card-header">常见报错分类</h2>
                <div class="card-body">
                    <div class="category-list">
                        <?php foreach ($categories as $category): ?>
                            <a href="/search?type=error&category=<?php echo $category['id']; ?>" class="category-item">
                                <div class="category-name"><?php echo h($category['name']); ?></div>
                                <div class="category-desc"><?php echo h($category['description']); ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <!-- 相关文章 -->
            <?php if (!empty($recentArticles)): ?>
            <section class="mb-20">
                <h2 class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    相关文章
                    <a href="/articles" class="btn btn-secondary btn-sm" style="font-size:0.8rem;">更多</a>
                </h2>
                <div class="card-body">
                    <div class="article-list article-list-grid">
                        <?php foreach ($recentArticles as $art): ?>
                            <?php $artTags = array_filter(array_map('trim', explode(',', $art['tags']))); ?>
                            <a class="article-card" href="<?php echo urlArticle($art['id']); ?>">
                                <h3 class="article-list-title">
                                    <?php echo h(mb_substr($art['title'], 0, 50) . (mb_strlen($art['title']) > 50 ? '...' : '')); ?>
                                </h3>
                                <div class="article-list-meta">
                                    <span class="article-author-inline"><?php echo h($art['username']); ?></span>
                                    <span><?php echo date('Y-m-d', strtotime($art['created_at'])); ?></span>
                                    <span class="view-count-inline" title="浏览量">
                                        <svg class="view-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <?php echo $articleViews[$art['id']] ?? 0; ?>
                                    </span>
                                    <span class="comment-count-inline" title="评论数">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                        <?php echo $articleCommentCounts[$art['id']] ?? 0; ?>
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
                                    <?php echo h(mb_substr(strip_tags($art['content']), 0, 50)); ?>...
                                </p>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <!-- 最新收录游戏 -->
            <section class="mb-20">
                <h2 class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    最新收录游戏
                    <a href="/games" class="btn btn-secondary btn-sm" style="font-size:0.8rem;">更多</a>
                </h2>
                <div class="card-body">
                    <div class="game-list">
                        <?php foreach ($games as $game): ?>
                            <a href="<?php echo urlGame($game['id']); ?>" class="game-item">
                                <?php $coverUrl = getCoverUrl($game); ?>
                                <?php if ($coverUrl): ?>
                                    <img src="<?php echo h($coverUrl); ?>" alt="<?php echo hs($game['title']); ?>" class="game-cover" loading="lazy">
                                <?php else: ?>
                                    <div class="game-cover no-cover">暂无封面</div>
                                <?php endif; ?>
                                <div class="game-title"><?php echo hs($game['title']); ?></div>
                                <div class="game-info">
                                    <?php if ($game['developer']): ?>
                                        <div>开发商：<?php echo h($game['developer']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($game['release_date']): ?>
                                        <div>发售日：<?php echo h($game['release_date']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($game['vndb_id']): ?>
                                        <div>VNDB：<?php echo h($game['vndb_id']); ?></div>
                                    <?php endif; ?>
                                    <div class="view-count-inline" title="浏览量">
                                        <svg class="view-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <?php echo $gameViews[$game['id']] ?? 0; ?>
                                    </div>
                                    <div class="comment-count-inline" title="报错数">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                                        <?php echo $gameErrorCounts[$game['id']] ?? 0; ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <!-- 最新解决方案 -->
            <?php if (!empty($recentErrors)): ?>
            <section>
                <h2 class="card-header">最新解决方案</h2>
                <div class="card-body">
                    <?php foreach ($recentErrors as $error): ?>
                        <a class="error-card" href="<?php echo urlError($error['id']); ?>">
                            <h3 class="error-title">
                                <?php echo h($error['title']); ?>
                            </h3>
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
                                        <p class="error-solution-summary"><?php echo h(mb_substr($solutionSummary, 0, 70) . (mb_strlen($solutionSummary) > 70 ? '...' : '')); ?></p>
                                    <?php else: ?>
                                        <p class="text-muted">暂无解决方案，等待补充</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <!-- 讨论区 -->
            <?php if (!empty($recentDiscussions)): ?>
            <section class="mb-20">
                <h2 class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
                    讨论区
                    <a href="/discussions" class="btn btn-secondary btn-sm" style="font-size:0.8rem;">更多</a>
                </h2>
                <div class="card-body">
                    <div class="article-list article-list-grid">
                        <?php foreach ($recentDiscussions as $disc): ?>
                            <?php $discTags = array_filter(array_map('trim', explode(',', $disc['tags']))); ?>
                            <a class="article-card" href="<?php echo urlDiscussion($disc['id']); ?>">
                                <h3 class="article-list-title">
                                    <?php echo h(mb_substr($disc['title'], 0, 50) . (mb_strlen($disc['title']) > 50 ? '...' : '')); ?>
                                </h3>
                                <div class="article-list-meta">
                                    <span class="article-author-inline"><?php echo h($disc['username']); ?></span>
                                    <span><?php echo date('Y-m-d', strtotime($disc['created_at'])); ?></span>
                                    <span class="view-count-inline" title="浏览量">
                                        <svg class="view-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <?php echo $discViews[$disc['id']] ?? 0; ?>
                                    </span>
                                    <span class="comment-count-inline" title="评论数">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                        <?php echo $discCommentCounts[$disc['id']] ?? 0; ?>
                                    </span>
                                </div>
                                <?php if (!empty($discTags)): ?>
                                    <div class="article-list-tags">
                                        <?php foreach (array_slice($discTags, 0, 3) as $t): ?>
                                            <span class="article-tag-sm"><?php echo h($t); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <p class="article-list-summary">
                                    <?php echo h(mb_substr(strip_tags($disc['content']), 0, 50)); ?>...
                                </p>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>