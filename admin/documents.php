<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

checkLogin();
requirePermission('documents', 'view');

$pdo = getDB();
$message = '';
$messageType = '';

$action = $_GET['action'] ?? '';

// 处理图片上传 (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_image') {
    requirePermission('documents', 'add');
    header('Content-Type: application/json');
    if (!isset($_FILES['image'])) {
        echo json_encode(['success' => false, 'message' => '未选择文件']);
        exit;
    }
    $result = handleDocImageUpload($_FILES['image']);
    if ($result['success']) {
        $result['url'] = '/' . $result['path'];
    }
    echo json_encode($result);
    exit;
}

// 添加文档
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('documents', 'add');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $link = trim($_POST['link'] ?? '');
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $sortOrder = intval($_POST['sort_order'] ?? 0);
    $image = '';

    if (empty($title)) {
        $message = '标题不能为空';
        $messageType = 'error';
    } else {
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $result = handleDocImageUpload($_FILES['image']);
            if ($result['success']) {
                $image = $result['path'];
            } else {
                $message = $result['message'];
                $messageType = 'error';
            }
        }

        if ($messageType !== 'error') {
            $pdo->prepare("INSERT INTO documents (title, description, content, image, link, enabled, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$title, $description, $content, $image, $link, $enabled, $sortOrder]);
            $message = '文档添加成功';
            $messageType = 'success';
            $action = '';
        }
    }
}

// 编辑文档
if ($action === 'edit' && isset($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('documents', 'edit');
    $id = intval($_GET['id']);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $link = trim($_POST['link'] ?? '');
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $sortOrder = intval($_POST['sort_order'] ?? 0);

    if (empty($title)) {
        $message = '标题不能为空';
        $messageType = 'error';
    } else {
        $imageUpdate = '';
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $result = handleDocImageUpload($_FILES['image']);
            if ($result['success']) {
                $stmt = $pdo->prepare("SELECT image FROM documents WHERE id = ?");
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
                $pdo->prepare("UPDATE documents SET title=?, description=?, content=?, image=?, link=?, enabled=?, sort_order=? WHERE id=?")
                    ->execute([$title, $description, $content, $imageUpdate, $link, $enabled, $sortOrder, $id]);
            } else {
                $pdo->prepare("UPDATE documents SET title=?, description=?, content=?, link=?, enabled=?, sort_order=? WHERE id=?")
                    ->execute([$title, $description, $content, $link, $enabled, $sortOrder, $id]);
            }
            $message = '文档更新成功';
            $messageType = 'success';
            $action = '';
        }
    }
}

// 删除文档
if ($action === 'delete' && isset($_GET['id'])) {
    requirePermission('documents', 'delete');
    $id = intval($_GET['id']);
    $stmt = $pdo->prepare("SELECT image FROM documents WHERE id = ?");
    $stmt->execute([$id]);
    $doc = $stmt->fetch();
    if ($doc && $doc['image'] && file_exists(BASE_PATH . $doc['image'])) {
        unlink(BASE_PATH . $doc['image']);
    }
    $pdo->prepare("DELETE FROM documents WHERE id = ?")->execute([$id]);
    $message = '文档已删除';
    $messageType = 'success';
    $action = '';
}

// 切换启用/禁用
if ($action === 'toggle' && isset($_GET['id'])) {
    requirePermission('documents', 'edit');
    $id = intval($_GET['id']);
    $pdo->prepare("UPDATE documents SET enabled = NOT enabled WHERE id = ?")->execute([$id]);
    $message = '状态已切换';
    $messageType = 'success';
    $action = '';
}

// 获取文档列表
$documents = $pdo->query("SELECT * FROM documents ORDER BY sort_order ASC, id DESC")->fetchAll();

// 获取编辑的文档
$editDoc = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $editDoc = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>文档管理 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <?php renderAdminHeadScript(); ?>
    <link rel="stylesheet" href="/assets/css/markdown-editor.css">
</head>
<body class="has-fixed-nav">
    <?php include '../includes/header.php'; ?>
    <div class="admin-layout">
        <?php renderAdminSidebar('documents.php'); ?>

        <div class="admin-content">
            <main class="admin-main">
                <div class="admin-page-header">
                    <h1>文档管理</h1>
                    <a href="?action=add" class="btn<?php echo pd('documents','add'); ?>"<?php echo pdAttr('documents','add'); ?>>添加文档</a>
                </div>
                <?php if ($message): ?>
                    <div class="admin-alert-<?php echo $messageType; ?>">
                        <?php echo h($message); ?>
                    </div>
                <?php endif; ?>

                <?php if ($action === 'add' || ($action === 'edit' && $editDoc)): ?>
                    <!-- 添加/编辑文档 -->
                    <div class="card">
                        <div class="card-header"><?php echo $action === 'add' ? '添加文档' : '编辑文档'; ?></div>
                        <div class="card-body">
                            <form method="post" enctype="multipart/form-data">
                                <div class="form-group">
                                    <label class="form-label">标题 *</label>
                                    <input type="text" name="title" class="form-input" required value="<?php echo h($editDoc['title'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">简介</label>
                                    <textarea name="description" class="form-textarea" rows="3" placeholder="显示在轮播卡片底部的简短描述"><?php echo h($editDoc['description'] ?? ''); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">正文内容</label>
                                    <div class="md-editor-wrap">
                                        <div class="md-mode-tabs">
                                            <button type="button" class="md-mode-tab active" data-mode="edit">编写</button>
                                            <button type="button" class="md-mode-tab" data-mode="preview">预览</button>
                                            <button type="button" class="md-mode-tab" data-mode="split">分栏</button>
                                        </div>
                                        <div class="md-editor-toolbar">
                                            <button type="button" data-action="h1" title="一级标题">H1</button>
                                            <button type="button" data-action="h2" title="二级标题">H2</button>
                                            <button type="button" data-action="h3" title="三级标题">H3</button>
                                            <span class="md-toolbar-separator"></span>
                                            <button type="button" data-action="bold" title="加粗"><b>B</b></button>
                                            <button type="button" data-action="italic" title="斜体"><i>I</i></button>
                                            <button type="button" data-action="strikethrough" title="删除线"><s>S</s></button>
                                            <span class="md-toolbar-separator"></span>
                                            <button type="button" data-action="link" title="链接">&#128279;</button>
                                            <button type="button" data-action="image" title="插入图片">&#128247; 图片</button>
                                            <span class="md-toolbar-separator"></span>
                                            <button type="button" data-action="code" title="行内代码">`</button>
                                            <button type="button" data-action="codeblock" title="代码块">&lt;/&gt;</button>
                                            <span class="md-toolbar-separator"></span>
                                            <button type="button" data-action="ul" title="无序列表">&#8226; 列表</button>
                                            <button type="button" data-action="ol" title="有序列表">1. 列表</button>
                                            <button type="button" data-action="quote" title="引用">&gt; 引用</button>
                                            <span class="md-toolbar-separator"></span>
                                            <button type="button" data-action="hr" title="水平线">---</button>
                                            <button type="button" data-action="table" title="表格">表格</button>
                                            <button type="button" data-action="tsvtable" title="将文本快速转换为表格">文本转表</button>
                                        </div>
                                        <div class="md-editor-body mode-edit">
                                            <textarea id="md-editor" class="md-editor-textarea" placeholder="支持 Markdown 语法，输入内容后可切换预览查看效果..."><?php echo h($editDoc['content'] ?? ''); ?></textarea>
                                            <div id="md-preview" class="md-editor-preview-pane"></div>
                                        </div>
                                        <div class="md-editor-hint">支持 Markdown 语法 | <kbd>Tab</kbd> 缩进 | <kbd>Shift+Tab</kbd> 取消缩进</div>
                                    </div>
                                    <input type="hidden" name="content" id="contentInput">
                                    <input type="file" id="md-image-uploader" accept="image/*" style="display:none;">
                                    <p class="form-hint">点击文档后跳转到详情页展示此内容</p>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">背景图片</label>
                                    <?php if ($editDoc && $editDoc['image']): ?>
                                        <div class="image-preview" style="margin-bottom: 10px;">
                                            <img src="/<?php echo h($editDoc['image']); ?>" alt="当前背景图" style="max-width: 320px; max-height: 160px; border-radius: 8px;">
                                        </div>
                                    <?php endif; ?>
                                    <input type="file" name="image" class="form-input" accept="image/*" style="max-width: 400px;">
                                    <p class="form-hint">推荐尺寸 1920x900，支持 JPG/PNG/GIF/WebP，最大 5MB</p>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">链接地址</label>
                                    <input type="url" name="link" class="form-input" value="<?php echo h($editDoc['link'] ?? ''); ?>" placeholder="https://...">
                                    <p class="form-hint">可选，填写后点击轮播将跳转到此链接而非详情页</p>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">排序</label>
                                    <input type="number" name="sort_order" class="form-input" value="<?php echo h($editDoc['sort_order'] ?? 0); ?>" min="0" style="max-width: 200px;">
                                    <p class="form-hint">数值越小越靠前</p>
                                </div>
                                <div class="form-group">
                                    <label style="font-weight: normal; cursor: pointer;">
                                        <input type="checkbox" name="enabled" value="1" <?php echo ($editDoc['enabled'] ?? 1) ? 'checked' : ''; ?>> 启用
                                    </label>
                                </div>
                                <div class="btn-group">
                                    <button type="submit" class="btn"><?php echo $action === 'add' ? '添加' : '更新'; ?></button>
                                    <a href="documents.php" class="btn btn-secondary">取消</a>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- 文档列表 -->
                    <div class="card">
                        <div class="card-header">
                            文档列表
                            <span style="float: right; color: var(--text-muted); font-weight: normal;">共 <?php echo count($documents); ?> 条</span>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>排序</th>
                                            <th>背景图</th>
                                            <th>标题</th>
                                            <th>简介</th>
                                            <th>状态</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($documents)): ?>
                                            <tr><td colspan="6" class="text-center text-muted" style="padding: 40px;">暂无文档</td></tr>
                                        <?php endif; ?>
                                        <?php foreach ($documents as $doc): ?>
                                            <tr>
                                                <td><?php echo $doc['sort_order']; ?></td>
                                                <td>
                                                    <?php if ($doc['image']): ?>
                                                        <img src="/<?php echo h($doc['image']); ?>" alt="" style="max-width: 100px; max-height: 50px; border-radius: 4px; object-fit: cover;">
                                                    <?php else: ?>
                                                        <span class="text-muted">无</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><strong><?php echo h($doc['title']); ?></strong></td>
                                                <td><?php echo h(mb_substr($doc['description'] ?? '', 0, 40)); ?><?php echo mb_strlen($doc['description'] ?? '') > 40 ? '...' : ''; ?></td>
                                                <td>
                                                    <span class="status-<?php echo $doc['enabled'] ? 'enabled' : 'disabled'; ?>">
                                                        <?php echo $doc['enabled'] ? '启用' : '禁用'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="action-btns">
                                                        <a href="?action=edit&id=<?php echo $doc['id']; ?>" class="btn btn-sm<?php echo pd('documents','edit'); ?>"<?php echo pdAttr('documents','edit'); ?>>编辑</a>
                                                        <a href="?action=toggle&id=<?php echo $doc['id']; ?>" class="btn btn-sm btn-secondary<?php echo pd('documents','edit'); ?>"<?php echo pdAttr('documents','edit'); ?>>
                                                            <?php echo $doc['enabled'] ? '禁用' : '启用'; ?>
                                                        </a>
                                                        <a href="?action=delete&id=<?php echo $doc['id']; ?>" class="btn btn-sm btn-danger<?php echo pd('documents','delete'); ?>"<?php echo pdAttr('documents','delete'); ?> onclick="<?php echo hasPermission('documents','delete') ? "return confirm('确定删除该文档？')" : 'return false;'; ?>">删除</a>
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
    <?php if ($action === 'add' || ($action === 'edit' && $editDoc)): ?>
    <script src="/assets/js/marked.min.js"></script>
    <script src="/assets/js/markdown-editor.js"></script>
    <script>
    MarkdownEditor.init({
        textareaId: 'md-editor',
        previewId: 'md-preview',
        imageUploadUrl: 'documents.php'
    });

    // 表单提交前将编辑器内容写入hidden input
    document.querySelector('form[method="post"]').addEventListener('submit', function() {
        var textarea = document.getElementById('md-editor');
        document.getElementById('contentInput').value = textarea.value;
    });
    </script>
    <?php endif; ?>
</body>
</html>

