<?php
require_once 'includes/user_auth.php';
require_once 'includes/image_utils.php';
require_once 'includes/sanitizer.php';

$pdo = getDB();
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 24;

// 总数
$stmt = $pdo->query("SELECT COUNT(*) as total FROM games WHERE status = 'approved'");
$total = $stmt->fetch()['total'];

$pagination = paginate($total, $page, $perPage);

// 游戏列表
$sql = "SELECT * FROM games WHERE status = 'approved' ORDER BY created_at DESC LIMIT {$pagination['offset']}, {$perPage}";
$stmt = $pdo->query($sql);
$games = $stmt->fetchAll();

// 批量获取浏览量
$gameIds = array_column($games, 'id');
$gameViewCounts = getViewCountsBatch('game', $gameIds);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>游戏列表 - <?php echo h(SITE_NAME); ?></title>
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
                    <h2>游戏列表</h2>
                    <p class="text-muted" style="margin-top:4px;font-size:0.85rem;">共 <?php echo $total; ?> 款游戏</p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <a href="/" class="btn btn-secondary">返回首页</a>
                    <a href="/submit_game" class="btn">提交游戏</a>
                </div>
            </div>

            <?php if (!empty($games)): ?>
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
                                    <?php echo $gameViewCounts[$game['id']] ?? 0; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- 分页 -->
                <?php if ($pagination['totalPages'] > 1): ?>
                    <div class="pagination">
                        <?php if ($pagination['hasPrev']): ?>
                            <a href="?page=<?php echo $pagination['page'] - 1; ?>">上一页</a>
                        <?php endif; ?>
                        <?php for ($i = max(1, $pagination['page'] - 2); $i <= min($pagination['totalPages'], $pagination['page'] + 2); $i++): ?>
                            <?php if ($i == $pagination['page']): ?>
                                <span class="current"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($pagination['hasNext']): ?>
                            <a href="?page=<?php echo $pagination['page'] + 1; ?>">下一页</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="card">
                    <div class="card-body text-center empty-state">
                        <p>暂无游戏</p>
                        <p class="text-muted">来提交第一款游戏吧！</p>
                        <a href="/submit_game" class="btn" style="margin-top:12px;">提交游戏</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
