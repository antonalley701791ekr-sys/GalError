<?php
require_once 'includes/user_auth.php';
requireUserLogin();

$pdo = getDB();
$status = $_GET['status'] ?? '';
$where = [];
$params = [];

if ($status && in_array($status, ['pending', 'completed', 'cancelled'], true)) {
    $where[] = 'status = ?';
    $params[] = $status;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$stmt = $pdo->prepare("SELECT * FROM todos {$whereClause} ORDER BY sort_order ASC, created_at DESC");
$stmt->execute($params);
$todos = $stmt->fetchAll();

$todoStats = [
    'total' => $pdo->query("SELECT COUNT(*) as c FROM todos")->fetch()['c'],
    'pending' => $pdo->query("SELECT COUNT(*) as c FROM todos WHERE status = 'pending'")->fetch()['c'],
    'completed' => $pdo->query("SELECT COUNT(*) as c FROM todos WHERE status = 'completed'")->fetch()['c'],
    'cancelled' => $pdo->query("SELECT COUNT(*) as c FROM todos WHERE status = 'cancelled'")->fetch()['c'],
];

$statusText = ['pending' => '进行中', 'completed' => '已完成', 'cancelled' => '已取消'];
$statusClassMap = ['pending' => 'status-pending', 'completed' => 'status-approved', 'cancelled' => 'status-cancelled'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>网站待办 - <?php echo h(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/user.css?v=<?php echo ASSETS_VER; ?>">
    <?php renderSiteHead(); ?>
</head>
<body class="has-fixed-nav">
    <?php include 'includes/header.php'; ?>

    <main class="main">
        <div class="container" style="max-width: 980px;">
            <div class="discussion-header">
                <div>
                    <h2>网站待办</h2>
                    <p class="text-muted" style="margin-top:4px;font-size:0.9rem;">公开展示网站计划与进展，登录用户均可查看</p>
                </div>
                <a href="/" class="btn btn-secondary">返回首页</a>
            </div>

            <div class="filter-tabs">
                <a href="/todos" class="<?php echo !$status ? 'active' : ''; ?>">全部 (<?php echo $todoStats['total']; ?>)</a>
                <a href="/todos?status=pending" class="<?php echo $status === 'pending' ? 'active' : ''; ?>">进行中 (<?php echo $todoStats['pending']; ?>)</a>
                <a href="/todos?status=completed" class="<?php echo $status === 'completed' ? 'active' : ''; ?>">已完成 (<?php echo $todoStats['completed']; ?>)</a>
                <a href="/todos?status=cancelled" class="<?php echo $status === 'cancelled' ? 'active' : ''; ?>">已取消 (<?php echo $todoStats['cancelled']; ?>)</a>
            </div>

            <?php if (!empty($todos)): ?>
                <div class="todo-public-list">
                    <?php foreach ($todos as $todo): ?>
                        <?php $todoStatusClass = $statusClassMap[$todo['status']] ?? 'status-pending'; ?>
                        <article class="card todo-public-card">
                            <div class="card-body">
                                <div class="todo-public-header">
                                    <div>
                                        <h3 class="todo-public-title"><?php echo h($todo['title']); ?></h3>
                                        <div class="todo-public-meta">
                                            <span>作者：<?php echo h($todo['author'] ?: '管理员'); ?></span>
                                            <span>创建：<?php echo date('Y-m-d H:i', strtotime($todo['created_at'])); ?></span>
                                            <span>完成：<?php echo !empty($todo['completed_at']) ? date('Y-m-d H:i', strtotime($todo['completed_at'])) : '未完成'; ?></span>
                                        </div>
                                    </div>
                                    <span class="status <?php echo $todoStatusClass; ?>"><?php echo h($statusText[$todo['status']] ?? $todo['status']); ?></span>
                                </div>
                                <?php if (!empty($todo['description'])): ?>
                                    <p class="todo-public-description"><?php echo nl2br(h($todo['description'])); ?></p>
                                <?php else: ?>
                                    <p class="todo-public-description text-muted">暂无描述</p>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body text-center empty-state">
                        <p>暂无待办事项</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
