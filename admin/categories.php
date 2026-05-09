<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

checkLogin();
requirePermission('categories', 'view');

$pdo = getDB();
$message = '';
$messageType = '';

// 处理各种操作
$action = $_GET['action'] ?? '';

if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $sortOrder = intval($_POST['sort_order'] ?? 0);
    
    if (empty($name)) {
        $message = '分类名称不能为空';
        $messageType = 'error';
    } else {
        // 检查名称是否重复
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM error_categories WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->fetch()['count'] > 0) {
            $message = '分类名称已存在';
            $messageType = 'error';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO error_categories (name, description, sort_order) 
                VALUES (?, ?, ?)
            ");
            $result = $stmt->execute([$name, $description, $sortOrder]);
            
            if ($result) {
                $message = '分类添加成功';
                $messageType = 'success';
            } else {
                $message = '分类添加失败';
                $messageType = 'error';
            }
        }
    }
}

if ($action === 'edit' && isset($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_GET['id']);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $sortOrder = intval($_POST['sort_order'] ?? 0);
    
    if (empty($name)) {
        $message = '分类名称不能为空';
        $messageType = 'error';
    } else {
        // 检查名称是否重复（排除自己）
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM error_categories WHERE name = ? AND id != ?");
        $stmt->execute([$name, $id]);
        if ($stmt->fetch()['count'] > 0) {
            $message = '分类名称已存在';
            $messageType = 'error';
        } else {
            $stmt = $pdo->prepare("
                UPDATE error_categories 
                SET name = ?, description = ?, sort_order = ? 
                WHERE id = ?
            ");
            $result = $stmt->execute([$name, $description, $sortOrder, $id]);
            
            if ($result) {
                $message = '分类更新成功';
                $messageType = 'success';
                $action = ''; // 返回列表
            } else {
                $message = '分类更新失败';
                $messageType = 'error';
            }
        }
    }
}

if ($action === 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    
    // 检查是否有关联的报错
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM errors WHERE category_id = ?");
    $stmt->execute([$id]);
    $errorCount = $stmt->fetch()['count'];
    
    if ($errorCount > 0) {
        $message = '该分类下还有报错记录，无法删除';
        $messageType = 'error';
    } else {
        $stmt = $pdo->prepare("DELETE FROM error_categories WHERE id = ?");
        $result = $stmt->execute([$id]);
        
        if ($result) {
            $message = '分类删除成功';
            $messageType = 'success';
        } else {
            $message = '分类删除失败';
            $messageType = 'error';
        }
    }
}

// 获取分类列表
$categories = $pdo->query("SELECT * FROM error_categories ORDER BY sort_order ASC, id ASC")->fetchAll();

// 获取编辑的分类
$editCategory = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM error_categories WHERE id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $editCategory = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>报错分类管理 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <?php renderAdminHeadScript(); ?>
</head>
<body class="has-fixed-nav">
    <?php include '../includes/header.php'; ?>
    <div class="admin-layout">
        <!-- 侧边栏 -->
        <?php renderAdminSidebar('categories.php'); ?>

        <!-- 主内容区 -->
        <div class="admin-content">
            <!-- 主要内容 -->
            <main class="admin-main">
                <div class="admin-page-header">
                    <h1>报错分类管理</h1>
                    <a href="?action=add" class="btn<?php echo pd('categories','add'); ?>"<?php echo pdAttr('categories','add'); ?>>添加分类</a>
                </div>
                <?php if ($message): ?>
                    <div class="admin-alert-<?php echo $messageType; ?>">
                        <?php echo h($message); ?>
                    </div>
                <?php endif; ?>

                <?php if ($action === 'add' || ($action === 'edit' && $editCategory)): ?>
                    <!-- 添加/编辑分类表单 -->
                    <div class="card">
                        <div class="card-header">
                            <?php echo $action === 'add' ? '添加分类' : '编辑分类'; ?>
                        </div>
                        <div class="card-body">
                            <form method="post">
                                <div class="form-group">
                                    <label class="form-label">分类名称 *</label>
                                    <input type="text" name="name" class="form-input" required 
                                           value="<?php echo h($editCategory['name'] ?? ''); ?>">
                                </div>

                                <div class="form-group">
                                    <label class="form-label">描述</label>
                                    <textarea name="description" class="form-textarea" rows="3"><?php echo h($editCategory['description'] ?? ''); ?></textarea>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">排序</label>
                                    <input type="number" name="sort_order" class="form-input" 
                                           value="<?php echo h($editCategory['sort_order'] ?? 0); ?>" min="0">
                                    <small class="text-muted">数字越小排序越靠前</small>
                                </div>

                                <div class="form-group">
                                    <div class="btn-group">
                                        <button type="submit" class="btn">
                                            <?php echo $action === 'add' ? '添加分类' : '更新分类'; ?>
                                        </button>
                                        <a href="categories.php" class="btn btn-secondary">取消</a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- 分类列表 -->
                    <div class="card">
                        <div class="card-header">
                            分类列表
                            <span style="float: right; color: var(--text-muted); font-weight: normal;">
                                共 <?php echo count($categories); ?> 个分类
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>排序</th>
                                            <th>分类名称</th>
                                            <th>描述</th>
                                            <th>报错数量</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($categories as $category): ?>
                                            <?php 
                                            // 获取该分类下的报错数量
                                            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM errors WHERE category_id = ?");
                                            $stmt->execute([$category['id']]);
                                            $errorCount = $stmt->fetch()['count'];
                                            ?>
                                            <tr>
                                                <td><?php echo h($category['sort_order']); ?></td>
                                                <td><strong><?php echo h($category['name']); ?></strong></td>
                                                <td><?php echo h($category['description']); ?></td>
                                                <td>
                                                    <span class="badge" style="background: var(--glass-bg); color: var(--accent-purple); padding: 2px 6px; border-radius: 3px; font-size: 12px; border: 1px solid var(--glass-border);">
                                                        <?php echo $errorCount; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="?action=edit&id=<?php echo $category['id']; ?>" class="btn<?php echo pd('categories','edit'); ?>" style="font-size: 12px; padding: 4px 8px;"<?php echo pdAttr('categories','edit'); ?>>编辑</a>
                                                    <a href="?action=delete&id=<?php echo $category['id']; ?>" class="btn btn-danger<?php echo pd('categories','delete'); ?>" style="font-size: 12px; padding: 4px 8px;"<?php echo pdAttr('categories','delete'); ?> onclick="<?php echo hasPermission('categories','delete') ? "return confirm('确定要删除这个分类吗？')" : 'return false;'; ?>">删除</a>
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

