<?php require_once '../includes/config.php';
require_once '../includes/auth.php';

checkLogin();

$pdo = getDB();

// 获取统计数据
$stats = [
    'total_games' => $pdo->query("SELECT COUNT(*) as count FROM games WHERE status = 'approved'")->fetch()['count'],
    'total_errors' => $pdo->query("SELECT COUNT(*) as count FROM errors")->fetch()['count'],
    'pending_errors' => $pdo->query("SELECT COUNT(*) as count FROM errors WHERE status = 'pending'")->fetch()['count'],
    'approved_errors' => $pdo->query("SELECT COUNT(*) as count FROM errors WHERE status = 'approved'")->fetch()['count'],
    'pending_games' => $pdo->query("SELECT COUNT(*) as count FROM games WHERE status = 'pending'")->fetch()['count'],
    'pending_articles' => $pdo->query("SELECT COUNT(*) as count FROM articles WHERE status = 'pending'")->fetch()['count'],
    'total_articles' => $pdo->query("SELECT COUNT(*) as count FROM articles WHERE status = 'approved'")->fetch()['count'],
];

// 获取最新的待审核报错
$recentErrors = $pdo->query("
    SELECT e.*, g.title as game_title, c.name as category_name 
    FROM errors e 
    JOIN games g ON e.game_id = g.id 
    JOIN error_categories c ON e.category_id = c.id 
    WHERE e.status = 'pending' 
    ORDER BY e.created_at DESC 
    LIMIT 5
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>控制台 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <?php renderAdminHeadScript(); ?>
</head>
<body class="has-fixed-nav">
    <?php include '../includes/header.php'; ?>
    <div class="admin-layout">
        <!-- 侧边栏 -->
        <?php renderAdminSidebar('index.php'); ?>

        <!-- 主内容区 -->
        <div class="admin-content">
            <!-- 主要内容 -->
            <main class="admin-main">
                <div class="admin-page-header">
                    <h1>控制台</h1>
                </div>
                <!-- 统计卡片 -->
                <div class="admin-stats-grid">
                    <div class="card stat-card">
                        <div class="card-body">
                            <h3 class="stat-number stat-number--cyan"><?php echo $stats['total_games']; ?></h3>
                            <p class="stat-label">收录游戏</p>
                        </div>
                    </div>
                    <div class="card stat-card">
                        <div class="card-body">
                            <h3 class="stat-number stat-number--green"><?php echo $stats['total_errors']; ?></h3>
                            <p class="stat-label">报错总数</p>
                        </div>
                    </div>
                    <div class="card stat-card">
                        <div class="card-body">
                            <h3 class="stat-number stat-number--yellow"><?php echo $stats['pending_errors']; ?></h3>
                            <p class="stat-label">待审核报错</p>
                        </div>
                    </div>
                    <div class="card stat-card">
                        <div class="card-body">
                            <h3 class="stat-number stat-number--orange"><?php echo $stats['pending_games']; ?></h3>
                            <p class="stat-label">待审核游戏</p>
                        </div>
                    </div>
                    <div class="card stat-card">
                        <div class="card-body">
                            <h3 class="stat-number stat-number--purple"><?php echo $stats['approved_errors']; ?></h3>
                            <p class="stat-label">已通过报错</p>
                        </div>
                    </div>
                    <div class="card stat-card">
                        <div class="card-body">
                            <h3 class="stat-number stat-number--pink"><?php echo $stats['pending_articles']; ?></h3>
                            <p class="stat-label">待审核文章</p>
                        </div>
                    </div>
                    <div class="card stat-card">
                        <div class="card-body">
                            <h3 class="stat-number stat-number--green"><?php echo $stats['total_articles']; ?></h3>
                            <p class="stat-label">已发布文章</p>
                        </div>
                    </div>
                </div>

                <!-- 最新待审核报错 -->
                <div class="card">
                    <div class="card-header">
                        最新待审核报错
                        <?php if ($stats['pending_errors'] > 0): ?>
                            <a href="errors.php?status=pending" class="btn btn-success" style="float: right; font-size: 14px; padding: 6px 12px;">查看全部</a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($recentErrors)): ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>标题</th>
                                            <th>游戏</th>
                                            <th>分类</th>
                                            <th>提交时间</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentErrors as $error): ?>
                                            <tr>
                                                <td><?php echo h($error['title']); ?></td>
                                                <td><?php echo h($error['game_title']); ?></td>
                                                <td><?php echo h($error['category_name']); ?></td>
                                                <td><?php echo date('Y-m-d H:i', strtotime($error['created_at'])); ?></td>
                                                <td>
                                                    <a href="errors.php?action=view&id=<?php echo $error['id']; ?>" class="btn" style="font-size: 12px; padding: 4px 8px;">查看</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center" style="padding: 40px;">
                                <span class="text-muted">暂无待审核的报错</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 快捷操作 -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 30px;">
                    <div class="card">
                        <div class="card-header">快捷操作</div>
                        <div class="card-body">
                            <div style="display: flex; flex-direction: column; gap: 10px;">
                                <a href="games.php?action=add" class="btn<?php echo pd('games','add'); ?>"<?php echo pdAttr('games','add'); ?>>添加游戏</a>
                                <a href="games.php?status=pending" class="btn btn-secondary<?php echo pd('game_review','view'); ?>"<?php echo pdAttr('game_review','view'); ?>>审核游戏</a>
                                <a href="categories.php?action=add" class="btn btn-secondary<?php echo pd('categories','add'); ?>"<?php echo pdAttr('categories','add'); ?>>添加分类</a>
                                <a href="errors.php?status=pending" class="btn btn-secondary<?php echo pd('errors','view'); ?>"<?php echo pdAttr('errors','view'); ?>>审核报错</a>
                                <a href="article_review.php?status=pending" class="btn btn-secondary<?php echo pd('articles','view'); ?>"<?php echo pdAttr('articles','view'); ?>>审核文章</a>
                            </div>
                        </div>
                    </div>


                </div>
            </main>
        </div>
    </div>
    <?php renderAdminFooterScripts(); ?>
</body>
</html>
