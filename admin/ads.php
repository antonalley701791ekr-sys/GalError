<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

checkLogin();
requirePermission('ads', 'view');

$pdo = getDB();
$message = '';
$messageType = '';

$action = $_GET['action'] ?? '';

// 添加广告
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('ads', 'add');
    $type = $_POST['type'] ?? 'text';
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $link = trim($_POST['link'] ?? '');
    $position = $_POST['position'] ?? 'header';
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $sortOrder = intval($_POST['sort_order'] ?? 0);
    $image = '';

    if (empty($title)) {
        $message = '标题不能为空';
        $messageType = 'error';
    } else {
        // 处理图片上传
        if ($type === 'image' && isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $result = handleAdImageUpload($_FILES['image']);
            if ($result['success']) {
                $image = $result['path'];
            } else {
                $message = $result['message'];
                $messageType = 'error';
            }
        }

        if ($messageType !== 'error') {
            $pdo->prepare("INSERT INTO ads (type, title, content, image, link, position, enabled, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$type, $title, $content, $image, $link, $position, $enabled, $sortOrder]);
            $message = '广告添加成功';
            $messageType = 'success';
            $action = '';
        }
    }
}

// 编辑广告
if ($action === 'edit' && isset($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('ads', 'edit');
    $id = intval($_GET['id']);
    $type = $_POST['type'] ?? 'text';
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $link = trim($_POST['link'] ?? '');
    $position = $_POST['position'] ?? 'header';
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $sortOrder = intval($_POST['sort_order'] ?? 0);

    if (empty($title)) {
        $message = '标题不能为空';
        $messageType = 'error';
    } else {
        // 处理新图片上传
        $imageUpdate = '';
        if ($type === 'image' && isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $result = handleAdImageUpload($_FILES['image']);
            if ($result['success']) {
                // 删除旧图片
                $stmt = $pdo->prepare("SELECT image FROM ads WHERE id = ?");
                $stmt->execute([$id]);
                $old = $stmt->fetch();
                if ($old && $old['image'] && file_exists(BASE_PATH . $old['image'])) {
                    unlink(BASE_PATH . $old['image']);
                }
                $imageUpdate = $result['path'];
            } else {
                $message = $result['message'];
                $messageType = 'error';
            }
        }

        if ($messageType !== 'error') {
            if ($imageUpdate) {
                $pdo->prepare("UPDATE ads SET type=?, title=?, content=?, image=?, link=?, position=?, enabled=?, sort_order=? WHERE id=?")
                    ->execute([$type, $title, $content, $imageUpdate, $link, $position, $enabled, $sortOrder, $id]);
            } else {
                $pdo->prepare("UPDATE ads SET type=?, title=?, content=?, link=?, position=?, enabled=?, sort_order=? WHERE id=?")
                    ->execute([$type, $title, $content, $link, $position, $enabled, $sortOrder, $id]);
            }
            $message = '广告更新成功';
            $messageType = 'success';
            $action = '';
        }
    }
}

// 删除广告
if ($action === 'delete' && isset($_GET['id'])) {
    requirePermission('ads', 'delete');
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("SELECT image FROM ads WHERE id = ?");
    $stmt->execute([$id]);
    $ad = $stmt->fetch();
    if ($ad && $ad['image'] && file_exists(BASE_PATH . $ad['image'])) {
        unlink(BASE_PATH . $ad['image']);
    }
    $pdo->prepare("DELETE FROM ads WHERE id = ?")->execute([$id]);
    $message = '广告已删除';
    $messageType = 'success';
    $action = '';
}

// 切换启用/禁用
if ($action === 'toggle' && isset($_GET['id'])) {
    requirePermission('ads', 'edit');
    $id = intval($_GET['id']);
    $pdo->prepare("UPDATE ads SET enabled = NOT enabled WHERE id = ?")->execute([$id]);
    $message = '状态已切换';
    $messageType = 'success';
    $action = '';
}

// 获取广告列表
$ads = $pdo->query("SELECT * FROM ads ORDER BY sort_order ASC, id DESC")->fetchAll();

// 获取编辑的广告
$editAd = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM ads WHERE id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $editAd = $stmt->fetch();
}

$typeText = ['image' => '图片', 'text' => '文字'];
$posText = ['header' => '顶部', 'sidebar' => '侧边栏', 'between' => '内容间'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>广告管理 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <?php renderAdminHeadScript(); ?>
</head>
<body class="has-fixed-nav">
    <?php include '../includes/header.php'; ?>
    <div class="admin-layout">
        <?php renderAdminSidebar('ads.php'); ?>

        <div class="admin-content">
            <main class="admin-main">
                <div class="admin-page-header">
                    <h1>广告管理</h1>
                    <a href="?action=add" class="btn<?php echo pd('ads','add'); ?>"<?php echo pdAttr('ads','add'); ?>>添加广告</a>
                </div>
                <?php if ($message): ?>
                    <div class="admin-alert-<?php echo $messageType; ?>">
                        <?php echo h($message); ?>
                    </div>
                <?php endif; ?>

                <?php if ($action === 'add' || ($action === 'edit' && $editAd)): ?>
                    <!-- 添加/编辑广告 -->
                    <div class="card">
                        <div class="card-header"><?php echo $action === 'add' ? '添加广告' : '编辑广告'; ?></div>
                        <div class="card-body">
                            <form method="post" enctype="multipart/form-data">
                                <div class="form-group">
                                    <label class="form-label">类型</label>
                                    <select name="type" class="form-select" style="max-width: 200px;" id="ad_type" onchange="toggleAdType()">
                                        <option value="text" <?php echo ($editAd['type'] ?? '') === 'text' ? 'selected' : ''; ?>>文字</option>
                                        <option value="image" <?php echo ($editAd['type'] ?? '') === 'image' ? 'selected' : ''; ?>>图片</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">标题 *</label>
                                    <input type="text" name="title" class="form-input" required value="<?php echo h($editAd['title'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">内容</label>
                                    <textarea name="content" class="form-textarea" rows="3"><?php echo h($editAd['content'] ?? ''); ?></textarea>
                                </div>
                                <div class="form-group" id="ad_image_group">
                                    <label class="form-label">广告图片</label>
                                    <?php if ($editAd && $editAd['image']): ?>
                                        <div class="image-preview" style="margin-bottom: 10px;">
                                            <img src="/<?php echo h($editAd['image']); ?>" alt="当前图片">
                                        </div>
                                    <?php endif; ?>
                                    <input type="file" name="image" class="form-input" accept="image/*">
                                    <p class="form-hint">支持 JPG/PNG/GIF，最大 5MB</p>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">链接地址</label>
                                    <input type="url" name="link" class="form-input" value="<?php echo h($editAd['link'] ?? ''); ?>" placeholder="https://...">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">展示位置</label>
                                    <select name="position" class="form-select" style="max-width: 200px;">
                                        <?php foreach ($posText as $val => $label): ?>
                                            <option value="<?php echo $val; ?>" <?php echo ($editAd['position'] ?? '') === $val ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">排序</label>
                                    <input type="number" name="sort_order" class="form-input" value="<?php echo h($editAd['sort_order'] ?? 0); ?>" min="0" style="max-width: 200px;">
                                </div>
                                <div class="form-group">
                                    <label style="font-weight: normal; cursor: pointer;">
                                        <input type="checkbox" name="enabled" value="1" <?php echo ($editAd['enabled'] ?? 1) ? 'checked' : ''; ?>> 启用
                                    </label>
                                </div>
                                <div class="btn-group">
                                    <button type="submit" class="btn"><?php echo $action === 'add' ? '添加' : '更新'; ?></button>
                                    <a href="ads.php" class="btn btn-secondary">取消</a>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- 广告列表 -->
                    <div class="card">
                        <div class="card-header">
                            广告列表
                            <span style="float: right; color: var(--text-muted); font-weight: normal;">共 <?php echo count($ads); ?> 条</span>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>排序</th>
                                            <th>类型</th>
                                            <th>标题</th>
                                            <th>位置</th>
                                            <th>状态</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($ads)): ?>
                                            <tr><td colspan="6" class="text-center text-muted" style="padding: 40px;">暂无广告</td></tr>
                                        <?php endif; ?>
                                        <?php foreach ($ads as $ad): ?>
                                            <tr>
                                                <td><?php echo $ad['sort_order']; ?></td>
                                                <td><?php echo $typeText[$ad['type']] ?? $ad['type']; ?></td>
                                                <td>
                                                    <strong><?php echo h($ad['title']); ?></strong>
                                                    <?php if ($ad['image']): ?>
                                                        <br><img src="/<?php echo h($ad['image']); ?>" alt="" style="max-width: 80px; max-height: 40px; border-radius: 4px; margin-top: 4px;">
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo $posText[$ad['position']] ?? $ad['position']; ?></td>
                                                <td>
                                                    <span class="status-<?php echo $ad['enabled'] ? 'enabled' : 'disabled'; ?>">
                                                        <?php echo $ad['enabled'] ? '启用' : '禁用'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="action-btns">
                                                        <a href="?action=edit&id=<?php echo $ad['id']; ?>" class="btn btn-sm<?php echo pd('ads','edit'); ?>"<?php echo pdAttr('ads','edit'); ?>>编辑</a>
                                                        <a href="?action=toggle&id=<?php echo $ad['id']; ?>" class="btn btn-sm btn-secondary<?php echo pd('ads','edit'); ?>"<?php echo pdAttr('ads','edit'); ?>>
                                                            <?php echo $ad['enabled'] ? '禁用' : '启用'; ?>
                                                        </a>
                                                        <a href="?action=delete&id=<?php echo $ad['id']; ?>" class="btn btn-sm btn-danger<?php echo pd('ads','delete'); ?>"<?php echo pdAttr('ads','delete'); ?> onclick="<?php echo hasPermission('ads','delete') ? "return confirm('确定删除？')" : 'return false;'; ?>">删除</a>
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

    <script>
    function toggleAdType() {
        var type = document.getElementById('ad_type').value;
        document.getElementById('ad_image_group').style.display = type === 'image' ? '' : 'none';
    }
    toggleAdType();
    </script>
    <?php renderAdminFooterScripts(); ?>
</body>
</html>

