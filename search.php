<?php
require_once 'includes/user_auth.php';
require_once 'includes/sanitizer.php';

$pdo = getDB();
$query = trim($_GET['q'] ?? '');
$searchType = $_GET['type'] ?? 'article'; // article, game, error, discussion
$category = intval($_GET['category'] ?? 0);
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;

$results = [];
$total = 0;
$validTypes = ['error', 'game', 'article', 'discussion'];
if (!in_array($searchType, $validTypes)) $searchType = 'article';

if ($query || $category) {
    $searchTerm = "%{$query}%";

    if ($searchType === 'game') {
        // 游戏搜索
        $where = [];
        $params = [];

        if ($query) {
            $where[] = "(g.title LIKE ? OR g.title_jp LIKE ? OR g.romaji LIKE ? OR g.aliases LIKE ? OR g.vndb_id LIKE ?)";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : 'WHERE 1=1';
        $whereClause .= " AND g.status = 'approved'";

        $countSql = "SELECT COUNT(*) as total FROM games g {$whereClause}";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetch()['total'];

        $pagination = paginate($total, $page, $perPage);

        $sql = "SELECT g.* FROM games g {$whereClause} ORDER BY g.created_at DESC LIMIT {$pagination['offset']}, {$perPage}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();

    } elseif ($searchType === 'article') {
        // 文章搜索
        $where = [];
        $params = [];

        if ($query) {
            // 检查是否精确匹配标签
            $where[] = "(a.title LIKE ? OR a.content LIKE ? OR FIND_IN_SET(?, a.tags) OR a.tags LIKE ?)";
            $params = array_merge($params, [$searchTerm, $searchTerm, $query, $searchTerm]);
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : 'WHERE 1=1';
        $whereClause .= " AND a.status = 'approved'";

        $countSql = "SELECT COUNT(*) as total FROM articles a JOIN users u ON a.user_id = u.id {$whereClause}";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetch()['total'];

        $pagination = paginate($total, $page, $perPage);

        $sql = "SELECT a.*, u.username FROM articles a JOIN users u ON a.user_id = u.id {$whereClause} ORDER BY a.created_at DESC LIMIT {$pagination['offset']}, {$perPage}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();

    } elseif ($searchType === 'discussion') {
        // 话题搜索
        $where = [];
        $params = [];

        if ($query) {
            $where[] = "(d.title LIKE ? OR d.content LIKE ? OR FIND_IN_SET(?, d.tags) OR d.tags LIKE ?)";
            $params = array_merge($params, [$searchTerm, $searchTerm, $query, $searchTerm]);
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : 'WHERE 1=1';
        $whereClause .= " AND d.status = 'active'";

        $countSql = "SELECT COUNT(*) as total FROM discussions d JOIN users u ON d.user_id = u.id {$whereClause}";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetch()['total'];

        $pagination = paginate($total, $page, $perPage);

        $sql = "SELECT d.*, u.username FROM discussions d JOIN users u ON d.user_id = u.id {$whereClause} ORDER BY d.created_at DESC LIMIT {$pagination['offset']}, {$perPage}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();

    } else {
        // 报错内容搜索（原有逻辑 + 标签精准匹配）
        $where = [];
        $params = [];

        if ($query) {
            $where[] = "(g.title LIKE ? OR g.title_jp LIKE ? OR g.romaji LIKE ? OR g.aliases LIKE ? OR g.vndb_id LIKE ? OR e.title LIKE ? OR e.phenomenon LIKE ? OR e.solution LIKE ?)";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        }

        if ($category) {
            $where[] = "e.category_id = ?";
            $params[] = $category;
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $whereClause .= ($whereClause ? ' AND ' : 'WHERE ') . "e.status = 'approved'";

        $countSql = "SELECT COUNT(DISTINCT e.id) as total FROM errors e JOIN games g ON e.game_id = g.id {$whereClause}";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetch()['total'];

        $pagination = paginate($total, $page, $perPage);

        $sql = "SELECT DISTINCT e.*, g.title as game_title, g.vndb_id, c.name as category_name
                FROM errors e
                JOIN games g ON e.game_id = g.id
                JOIN error_categories c ON e.category_id = c.id
                {$whereClause}
                ORDER BY e.created_at DESC
                LIMIT {$pagination['offset']}, {$perPage}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();
    }
} else {
    $pagination = paginate(0, $page, $perPage);
}

// 获取分类列表（仅报错分类时使用）
$stmt = $pdo->query("SELECT * FROM error_categories ORDER BY sort_order ASC");
$categories = $stmt->fetchAll();

// 批量获取搜索结果的浏览量
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

// 批量获取话题评论数
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
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>搜索结果 - <?php echo h(SITE_NAME); ?></title>
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
                        <input type="radio" name="type" value="article" <?php echo $searchType === 'article' ? 'checked' : ''; ?>>
                        <span>文章</span>
                    </label>
                    <label class="search-tab">
                        <input type="radio" name="type" value="game" <?php echo $searchType === 'game' ? 'checked' : ''; ?>>
                        <span>游戏</span>
                    </label>
                    <label class="search-tab">
                        <input type="radio" name="type" value="error" <?php echo $searchType === 'error' ? 'checked' : ''; ?>>
                        <span>报错内容</span>
                    </label>
                    <label class="search-tab">
                        <input type="radio" name="type" value="discussion" <?php echo $searchType === 'discussion' ? 'checked' : ''; ?>>
                        <span>话题</span>
                    </label>
                </div>
                <div class="search-input-row">
                    <input type="text" name="q" class="search-input" placeholder="搜索<?php echo $typeLabels[$searchType]; ?>..." value="<?php echo h($query); ?>">
                    <?php if ($searchType === 'error'): ?>
                        <select name="category" class="form-select search-category-select">
                            <option value="">所有分类</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo $category == $cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo h($cat['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <button type="submit" class="search-btn">搜索</button>
                    <?php if ($query || $category): ?>
                        <a href="/search" class="btn btn-secondary">清除</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </section>

    <?php renderAnnouncement(); ?>

    <!-- 主要内容 -->
    <main class="main">
        <div class="container">
            <?php if ($query || $category): ?>
                <div class="mb-20">
                    <h2>搜索结果 - <?php echo $typeLabels[$searchType]; ?></h2>
                    <p class="text-muted" style="margin-top: 10px;">
                        <?php if ($query): ?>
                            关键词：<strong><?php echo h($query); ?></strong>
                        <?php endif; ?>
                        <?php if ($category && $searchType === 'error'): ?>
                            <?php $currentCategory = null; foreach ($categories as $c) { if ($c['id'] == $category) { $currentCategory = $c; break; } } ?>
                            分类：<strong><?php echo h($currentCategory['name'] ?? ''); ?></strong>
                        <?php endif; ?>
                        <?php if ($total > 0): ?>
                            ，共找到 <strong><?php echo $total; ?></strong> 条结果
                        <?php endif; ?>
                    </p>
                </div>

                <?php if (!empty($results)): ?>
                    <div class="card">
                        <div class="card-body">
                            <?php if ($searchType === 'game'): ?>
                                <!-- 游戏结果 -->
                                <div class="game-list">
                                    <?php foreach ($results as $game): ?>
                                        <a href="<?php echo urlGame($game['id']); ?>" class="game-item">
                                            <?php
                                            $coverUrl = '';
                                            if (!empty($game['cover_image']) && file_exists(BASE_PATH . $game['cover_image'])) {
                                                $coverUrl = $game['cover_image'];
                                            } elseif (!empty($game['vndb_cover_url'])) {
                                                $coverUrl = '/image_proxy?url=' . urlencode($game['vndb_cover_url']);
                                            }
                                            ?>
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
                                                <?php if ($game['vndb_id']): ?>
                                                    <div>VNDB：<?php echo h($game['vndb_id']); ?></div>
                                                <?php endif; ?>
                                                <div class="view-count-inline" title="浏览量">
                                                    <svg class="view-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                    <?php echo $searchResultViews[$game['id']] ?? 0; ?>
                                                </div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>

                            <?php elseif ($searchType === 'article'): ?>
                                <!-- 文章结果 -->
                                <div class="article-list article-list-grid">
                                    <?php foreach ($results as $art): ?>
                                        <?php $artTags = array_filter(array_map('trim', explode(',', $art['tags']))); ?>
                                        <a class="article-card" href="<?php echo urlArticle($art['id']); ?>">
                                            <h3 class="article-list-title">
                                                <?php echo h($art['title']); ?>
                                            </h3>
                                            <div class="article-list-meta">
                                                <span class="article-author-inline"><?php echo h($art['username']); ?></span>
                                                <span><?php echo date('Y-m-d', strtotime($art['created_at'])); ?></span>
                                                <span class="view-count-inline" title="浏览量">
                                                    <svg class="view-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                    <?php echo $searchResultViews[$art['id']] ?? 0; ?>
                                                </span>
                                            </div>
                                            <?php if (!empty($artTags)): ?>
                                                <div class="article-list-tags">
                                                    <?php foreach (array_slice($artTags, 0, 5) as $t): ?>
                                                        <?php
                                                        $isMatch = ($query && (stripos($t, $query) !== false || $t === $query));
                                                        ?>
                                                        <span class="article-tag-sm <?php echo $isMatch ? 'tag-matched' : ''; ?>"><?php echo h($t); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                            <p class="article-list-summary">
                                                <?php echo h(mb_substr(strip_tags($art['content']), 0, 150)); ?>...
                                            </p>
                                        </a>
                                    <?php endforeach; ?>
                                </div>

                            <?php elseif ($searchType === 'discussion'): ?>
                                <!-- 话题结果 -->
                                <div class="article-list article-list-grid">
                                    <?php foreach ($results as $disc): ?>
                                        <?php $discTags = array_filter(array_map('trim', explode(',', $disc['tags']))); ?>
                                        <a class="article-card" href="<?php echo urlDiscussion($disc['id']); ?>">
                                            <h3 class="article-list-title">
                                                <?php echo h($disc['title']); ?>
                                            </h3>
                                            <div class="article-list-meta">
                                                <span class="article-author-inline"><?php echo h($disc['username']); ?></span>
                                                <span><?php echo date('Y-m-d', strtotime($disc['created_at'])); ?></span>
                                                <span class="view-count-inline" title="浏览量">
                                                    <svg class="view-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                    <?php echo $searchResultViews[$disc['id']] ?? 0; ?>
                                                </span>
                                                <span class="comment-count-inline" title="评论数">
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                                    <?php echo $searchDiscCommentCounts[$disc['id']] ?? 0; ?>
                                                </span>
                                            </div>
                                            <?php if (!empty($discTags)): ?>
                                                <div class="article-list-tags">
                                                    <?php foreach (array_slice($discTags, 0, 5) as $t): ?>
                                                        <?php
                                                        $isMatch = ($query && (stripos($t, $query) !== false || $t === $query));
                                                        ?>
                                                        <span class="article-tag-sm <?php echo $isMatch ? 'tag-matched' : ''; ?>"><?php echo h($t); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                            <p class="article-list-summary">
                                                <?php echo h(mb_substr(strip_tags($disc['content']), 0, 150)); ?>...
                                            </p>
                                        </a>
                                    <?php endforeach; ?>
                                </div>

                            <?php else: ?>
                                <!-- 报错结果 -->
                                <?php foreach ($results as $result): ?>
                                    <a class="error-card" href="<?php echo urlError($result['id']); ?>">
                                        <h3 class="error-title">
                                            <?php echo h($result['title']); ?>
                                        </h3>
                                        <div class="error-meta">
                                            <span>游戏：<?php echo hs($result['game_title']); ?></span>
                                            <?php if ($result['vndb_id']): ?>
                                                <span>VNDB：<?php echo h($result['vndb_id']); ?></span>
                                            <?php endif; ?>
                                            <span>分类：<span class="article-tag-sm <?php echo ($query && stripos($result['category_name'], $query) !== false) ? 'tag-matched' : ''; ?>"><?php echo h($result['category_name']); ?></span></span>
                                            <span>时间：<?php echo date('Y-m-d', strtotime($result['created_at'])); ?></span>
                                            <span class="view-count-inline" title="浏览量">
                                                <svg class="view-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                <?php echo $searchResultViews[$result['id']] ?? 0; ?>
                                            </span>
                                        </div>
                                        <?php if ($result['phenomenon']): ?>
                                        <div class="error-content">
                                            <div class="error-section">
                                                <h4>问题描述：</h4>
                                                <p><?php echo nl2br(h(mb_substr($result['phenomenon'], 0, 150) . (mb_strlen($result['phenomenon']) > 150 ? '...' : ''))); ?></p>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        <div class="error-content">
                                            <div class="error-section">
                                                <h4>解决方案：</h4>
                                                <p><?php echo nl2br(h(mb_substr($result['solution'], 0, 200) . (mb_strlen($result['solution']) > 200 ? '...' : ''))); ?></p>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 分页 -->
                    <?php if ($pagination['totalPages'] > 1): ?>
                        <div class="pagination">
                            <?php
                            $pagerParams = 'type=' . urlencode($searchType);
                            if ($query) $pagerParams .= '&q=' . urlencode($query);
                            if ($category) $pagerParams .= '&category=' . $category;
                            ?>
                            <?php if ($pagination['hasPrev']): ?>
                                <a href="?<?php echo $pagerParams; ?>&page=<?php echo $pagination['page'] - 1; ?>">上一页</a>
                            <?php endif; ?>
                            <?php for ($i = max(1, $pagination['page'] - 2); $i <= min($pagination['totalPages'], $pagination['page'] + 2); $i++): ?>
                                <?php if ($i == $pagination['page']): ?>
                                    <span class="current"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a href="?<?php echo $pagerParams; ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                            <?php if ($pagination['hasNext']): ?>
                                <a href="?<?php echo $pagerParams; ?>&page=<?php echo $pagination['page'] + 1; ?>">下一页</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="card">
                        <div class="card-body text-center empty-state">
                            <p>未找到相关结果</p>
                            <p class="text-muted">请尝试使用其他关键词或切换搜索分类</p>
                        </div>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="card">
                    <div class="card-body text-center empty-state">
                        <p>请输入搜索关键词或选择分类</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>

    <script>
    // 搜索分类切换时更新 placeholder
    document.querySelectorAll('.search-tab input').forEach(function(radio) {
        radio.addEventListener('change', function() {
            var labels = {article: '搜索文章标题/内容/标签...', game: '搜索游戏名称...', error: '搜索报错内容...', discussion: '搜索话题标题/内容/标签...'};
            document.querySelector('.search-input').placeholder = labels[this.value] || '';
            // 显示/隐藏分类下拉
            var catSelect = document.querySelector('.search-category-select');
            if (catSelect) {
                catSelect.style.display = this.value === 'error' ? '' : 'none';
            }
        });
    });
    </script>
</body>
</html>
