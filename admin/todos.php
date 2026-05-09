<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

checkLogin();
requirePermission('todos', 'view');

$pdo = getDB();
$message = '';
$messageType = '';

$action = $_GET['action'] ?? '';
$filterStatus = $_GET['status'] ?? '';

if (isset($_GET['added']) && $_GET['added'] === '1') {
    $message = '待办添加成功';
    $messageType = 'success';
} elseif (isset($_GET['updated']) && $_GET['updated'] === '1') {
    $message = '待办更新成功';
    $messageType = 'success';
} elseif (isset($_GET['deleted']) && $_GET['deleted'] === '1') {
    $message = '待办已删除';
    $messageType = 'success';
} elseif (isset($_GET['status_updated']) && $_GET['status_updated'] === '1') {
    $message = '状态已更新';
    $messageType = 'success';
}

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('todos', 'add');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $sortOrder = intval($_POST['sort_order'] ?? 0);
    $author = $_SESSION['admin_username'];

    if (empty($title)) {
        $message = '标题不能为空';
        $messageType = 'error';
    } else {
        $pdo->prepare("INSERT INTO todos (title, description, sort_order, author) VALUES (?, ?, ?, ?)")
            ->execute([$title, $description, $sortOrder, $author]);
        header('Location: todos.php?added=1');
        exit;
    }
}

if ($action === 'edit' && isset($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('todos', 'edit');
    $id = intval($_GET['id']);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'pending';
    $sortOrder = intval($_POST['sort_order'] ?? 0);
    $completedAtInput = trim($_POST['completed_at'] ?? '');
    $completedAt = null;
    if ($status === 'completed') {
        $completedTimestamp = $completedAtInput !== '' ? strtotime($completedAtInput) : false;
        $completedAt = $completedTimestamp ? date('Y-m-d H:i:s', $completedTimestamp) : date('Y-m-d H:i:s');
    }

    if (empty($title)) {
        $message = '标题不能为空';
        $messageType = 'error';
    } else {
        $pdo->prepare("UPDATE todos SET title = ?, description = ?, status = ?, sort_order = ?, completed_at = ? WHERE id = ?")
            ->execute([$title, $description, $status, $sortOrder, $completedAt, $id]);
        header('Location: todos.php?updated=1');
        exit;
    }
}

if ($action === 'delete' && isset($_GET['id'])) {
    requirePermission('todos', 'delete');
    $id = intval($_GET['id']);
    $pdo->prepare("DELETE FROM todos WHERE id = ?")->execute([$id]);
    header('Location: todos.php?deleted=1');
    exit;
}

if ($action === 'status' && isset($_GET['id']) && isset($_GET['status'])) {
    requirePermission('todos', 'edit');
    $id = intval($_GET['id']);
    $newStatus = $_GET['status'];
    if (in_array($newStatus, ['pending', 'completed', 'cancelled'])) {
        $completedAt = $newStatus === 'completed' ? date('Y-m-d H:i:s') : null;
        $pdo->prepare("UPDATE todos SET status = ?, completed_at = ? WHERE id = ?")->execute([$newStatus, $completedAt, $id]);
        header('Location: todos.php?status_updated=1' . ($filterStatus ? '&status=' . urlencode($filterStatus) : ''));
        exit;
    }
    $action = '';
}

$viewTodo = null;
if ($action === 'view' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM todos WHERE id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $viewTodo = $stmt->fetch();
    if (!$viewTodo) {
        $message = '待办不存在';
        $messageType = 'error';
        $action = '';
    }
}

$where = [];
$params = [];
if ($filterStatus && in_array($filterStatus, ['pending', 'completed', 'cancelled'])) {
    $where[] = "status = ?";
    $params[] = $filterStatus;
}
$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT * FROM todos {$whereClause} ORDER BY sort_order ASC, created_at DESC");
$stmt->execute($params);
$todos = $stmt->fetchAll();

$editTodo = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM todos WHERE id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $editTodo = $stmt->fetch();
}

$todoStats = [
    'total' => $pdo->query("SELECT COUNT(*) as c FROM todos")->fetch()['c'],
    'pending' => $pdo->query("SELECT COUNT(*) as c FROM todos WHERE status = 'pending'")->fetch()['c'],
    'completed' => $pdo->query("SELECT COUNT(*) as c FROM todos WHERE status = 'completed'")->fetch()['c'],
    'cancelled' => $pdo->query("SELECT COUNT(*) as c FROM todos WHERE status = 'cancelled'")->fetch()['c'],
];

$statusText = ['pending' => '进行中', 'completed' => '已完成', 'cancelled' => '已取消'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>网站待办 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <?php renderAdminHeadScript(); ?>
</head>
<body class="has-fixed-nav">
    <?php include '../includes/header.php'; ?>
    <div class="admin-layout">
        <?php renderAdminSidebar('todos.php'); ?>

        <div class="admin-content">
            <main class="admin-main">
                <div class="admin-page-header">
                    <h1>网站待办</h1>
                    <a href="?action=add" class="btn<?php echo pd('todos','add'); ?>"<?php echo pdAttr('todos','add'); ?>>添加待办</a>
                </div>
                <?php if ($message): ?>
                    <div class="admin-alert-<?php echo $messageType; ?>">
                        <?php echo h($message); ?>
                    </div>
                <?php endif; ?>

                <?php if ($action === 'view' && $viewTodo): ?>
                    <!-- 查看待办详情 -->
                    <div class="card">
                        <div class="card-header">
                            待办详情
                            <a href="todos.php<?php echo $filterStatus ? '?status=' . h($filterStatus) : ''; ?>" class="btn btn-sm btn-secondary" style="float: right;">返回列表</a>
                        </div>
                        <div class="card-body">
                            <div class="detail-grid">
                                <div class="detail-row">
                                    <div class="detail-label">标题</div>
                                    <div class="detail-value"><strong><?php echo h($viewTodo['title']); ?></strong></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">状态</div>
                                    <div class="detail-value">
                                        <?php
                                        $vStatusClass = 'status-pending';
                                        if ($viewTodo['status'] === 'completed') $vStatusClass = 'status-approved';
                                        if ($viewTodo['status'] === 'cancelled') $vStatusClass = 'status-cancelled';
                                        ?>
                                        <span class="<?php echo $vStatusClass; ?>">
                                            <?php echo $statusText[$viewTodo['status']] ?? $viewTodo['status']; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">描述</div>
                                    <div class="detail-value">
                                        <?php if ($viewTodo['description']): ?>
                                            <div style="white-space: pre-wrap;"><?php echo h($viewTodo['description']); ?></div>
                                        <?php else: ?>
                                            <span class="text-muted">无描述</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">排序权重</div>
                                    <div class="detail-value"><?php echo $viewTodo['sort_order']; ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">作者</div>
                                    <div class="detail-value"><?php echo h($viewTodo['author']); ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">创建时间</div>
                                    <div class="detail-value"><?php echo date('Y-m-d H:i:s', strtotime($viewTodo['created_at'])); ?></div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">完成时间</div>
                                    <div class="detail-value">
                                        <?php echo !empty($viewTodo['completed_at']) ? date('Y-m-d H:i:s', strtotime($viewTodo['completed_at'])) : '<span class="text-muted">未完成</span>'; ?>
                                    </div>
                                </div>
                                <div class="detail-row">
                                    <div class="detail-label">更新时间</div>
                                    <div class="detail-value"><?php echo date('Y-m-d H:i:s', strtotime($viewTodo['updated_at'])); ?></div>
                                </div>
                            </div>
                            <div class="btn-group" style="margin-top: 16px;">
                                <?php if (hasPermission('todos', 'edit')): ?>
                                    <a href="?action=edit&id=<?php echo $viewTodo['id']; ?>" class="btn">编辑</a>
                                <?php endif; ?>
                                <a href="todos.php<?php echo $filterStatus ? '?status=' . h($filterStatus) : ''; ?>" class="btn btn-secondary">返回列表</a>
                            </div>
                        </div>
                    </div>
                <?php elseif ($action === 'add' || ($action === 'edit' && $editTodo)): ?>
                    <!-- 添加/编辑待办 -->
                    <div class="card">
                        <div class="card-header"><?php echo $action === 'add' ? '添加待办' : '编辑待办'; ?></div>
                        <div class="card-body">
                            <form method="post">
                                <div class="form-group">
                                    <label class="form-label">标题 *</label>
                                    <input type="text" name="title" class="form-input" required value="<?php echo h($editTodo['title'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">描述</label>
                                    <textarea name="description" class="form-textarea" rows="4"><?php echo h($editTodo['description'] ?? ''); ?></textarea>
                                </div>
                                <?php if ($action === 'edit'): ?>
                                    <div class="form-group">
                                        <label class="form-label">状态</label>
                                        <select name="status" class="form-select" style="max-width: 200px;">
                                            <?php foreach ($statusText as $val => $label): ?>
                                                <option value="<?php echo $val; ?>" <?php echo ($editTodo['status'] ?? '') === $val ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">完成时间</label>
                                        <input type="datetime-local" name="completed_at" class="form-input" style="max-width: 240px;" value="<?php echo !empty($editTodo['completed_at']) ? date('Y-m-d\TH:i', strtotime($editTodo['completed_at'])) : ''; ?>">
                                        <p class="form-hint">状态为“已完成”时有效；留空则更新时自动使用当前时间</p>
                                    </div>
                                <?php endif; ?>
                                <div class="form-group">
                                    <label class="form-label">排序</label>
                                    <input type="number" name="sort_order" class="form-input" value="<?php echo h($editTodo['sort_order'] ?? 0); ?>" min="0" style="max-width: 200px;">
                                    <p class="form-hint">数字越小排序越靠前</p>
                                </div>
                                <?php if ($action === 'add'): ?>
                                    <div class="form-group">
                                        <label class="form-label">作者</label>
                                        <input type="text" class="form-input" value="<?php echo h($_SESSION['admin_username']); ?>" readonly style="max-width: 300px; opacity: 0.6;">
                                    </div>
                                <?php endif; ?>
                                <div class="btn-group">
                                    <button type="submit" class="btn"><?php echo $action === 'add' ? '添加' : '更新'; ?></button>
                                    <a href="todos.php" class="btn btn-secondary">取消</a>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- 状态筛选 -->
                    <div class="filter-tabs">
                        <a href="todos.php" class="<?php echo !$filterStatus ? 'active' : ''; ?>">全部 (<?php echo $todoStats['total']; ?>)</a>
                        <a href="todos.php?status=pending" class="<?php echo $filterStatus === 'pending' ? 'active' : ''; ?>">进行中 (<?php echo $todoStats['pending']; ?>)</a>
                        <a href="todos.php?status=completed" class="<?php echo $filterStatus === 'completed' ? 'active' : ''; ?>">已完成 (<?php echo $todoStats['completed']; ?>)</a>
                        <a href="todos.php?status=cancelled" class="<?php echo $filterStatus === 'cancelled' ? 'active' : ''; ?>">已取消 (<?php echo $todoStats['cancelled']; ?>)</a>
                    </div>

                    <!-- 待办列表 -->
                    <div class="card">
                        <div class="card-header">
                            待办列表
                            <span class="text-muted" style="float: right; font-weight: normal;">共 <?php echo count($todos); ?> 条</span>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table compact-mobile-list">
                                    <thead>
                                        <tr>
                                            <th>排序</th>
                                            <th>标题</th>
                                            <th>作者</th>
                                            <th>状态</th>
                                            <th>创建时间</th>
                                            <th>完成时间</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($todos)): ?>
                                            <tr><td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted);">暂无数据</td></tr>
                                        <?php endif; ?>
                                        <?php foreach ($todos as $todo): ?>
                                            <tr>
                                                <td><?php echo $todo['sort_order']; ?></td>
                                                <td>
                                                    <a href="?action=view&id=<?php echo $todo['id']; ?>" class="todo-title-link"><strong><?php echo h($todo['title']); ?></strong></a>
                                                    <?php if ($todo['description']): ?>
                                                        <br><small class="text-muted"><?php echo h(mb_substr($todo['description'], 0, 50)); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo h($todo['author']); ?></td>
                                                <td>
                                                    <?php
                                                    $statusClass = 'status-pending';
                                                    if ($todo['status'] === 'completed') $statusClass = 'status-approved';
                                                    if ($todo['status'] === 'cancelled') $statusClass = 'status-cancelled';
                                                    ?>
                                                    <span class="<?php echo $statusClass; ?>">
                                                        <?php echo $statusText[$todo['status']] ?? $todo['status']; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('Y-m-d H:i', strtotime($todo['created_at'])); ?></td>
                                                <td><?php echo !empty($todo['completed_at']) ? date('Y-m-d H:i', strtotime($todo['completed_at'])) : '<span class="text-muted">未完成</span>'; ?></td>
                                                <td>
                                                    <div class="action-btns">
                                                        <?php if ($todo['status'] === 'pending'): ?>
                                                            <a href="?action=status&id=<?php echo $todo['id']; ?>&status=completed" class="btn btn-sm btn-success<?php echo pd('todos','edit'); ?>"<?php echo pdAttr('todos','edit'); ?>>完成</a>
                                                            <a href="?action=status&id=<?php echo $todo['id']; ?>&status=cancelled" class="btn btn-sm btn-secondary<?php echo pd('todos','edit'); ?>"<?php echo pdAttr('todos','edit'); ?>>取消</a>
                                                        <?php elseif ($todo['status'] !== 'pending'): ?>
                                                            <a href="?action=status&id=<?php echo $todo['id']; ?>&status=pending" class="btn btn-sm btn-secondary<?php echo pd('todos','edit'); ?>"<?php echo pdAttr('todos','edit'); ?>>恢复</a>
                                                        <?php endif; ?>
                                                        <a href="?action=edit&id=<?php echo $todo['id']; ?>" class="btn btn-sm<?php echo pd('todos','edit'); ?>"<?php echo pdAttr('todos','edit'); ?>>编辑</a>
                                                        <a href="?action=delete&id=<?php echo $todo['id']; ?>" class="btn btn-sm btn-danger<?php echo pd('todos','delete'); ?>"<?php echo pdAttr('todos','delete'); ?> onclick="<?php echo hasPermission('todos','delete') ? "return confirm('确定删除？')" : 'return false;'; ?>">删除</a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    <?php renderAdminFooterScripts(); ?>
</body>
</html>

