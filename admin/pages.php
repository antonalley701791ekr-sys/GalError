<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

checkLogin();
requirePermission('site', 'view');

$pdo = getDB();
$message = '';
$messageType = '';

// Session 消息
if (isset($_SESSION['admin_msg'])) {
    $message = $_SESSION['admin_msg'];
    $messageType = $_SESSION['admin_msg_type'] ?? 'success';
    unset($_SESSION['admin_msg'], $_SESSION['admin_msg_type']);
}

$action = $_GET['action'] ?? '';
$slug = $_GET['slug'] ?? '';

// 页面元信息
$pageMeta = [
    'about'       => ['title' => '关于我们', 'desc' => '介绍网站用途、定位、宗旨'],
    'legal'       => ['title' => '版权及法律声明', 'desc' => '版权声明、法律条款'],
    'entry-guide' => ['title' => '入站须知', 'desc' => '新用户入站须知'],
    'admin-guide' => ['title' => '管理员须知', 'desc' => '管理员行为规范及操作须知'],
];

// 处理图片上传 (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_image') {
    requirePermission('site', 'edit');
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

// 保存编辑
if ($action === 'edit' && $slug && isset($pageMeta[$slug]) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('site', 'edit');
    $content = $_POST['content'] ?? '';

    $stmt = $pdo->prepare("UPDATE site_pages SET content = ? WHERE slug = ?");
    $stmt->execute([$content, $slug]);

    $_SESSION['admin_msg'] = '"' . $pageMeta[$slug]['title'] . '" 保存成功';
    $_SESSION['admin_msg_type'] = 'success';
    header('Location: pages.php');
    exit;
}

// 获取所有页面
$pages = $pdo->query("SELECT * FROM site_pages ORDER BY FIELD(slug, 'about', 'legal', 'entry-guide', 'admin-guide')")->fetchAll();

// 获取编辑页面
$editPage = null;
if ($action === 'edit' && $slug) {
    $stmt = $pdo->prepare("SELECT * FROM site_pages WHERE slug = ?");
    $stmt->execute([$slug]);
    $editPage = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>页面管理 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <?php renderAdminHeadScript(); ?>
    <link rel="stylesheet" href="/assets/css/markdown-editor.css">
</head>
<body class="has-fixed-nav">
    <?php include '../includes/header.php'; ?>
    <div class="admin-layout">
        <?php renderAdminSidebar('pages.php'); ?>

        <div class="admin-content">
            <main class="admin-main">
                <div class="admin-page-header">
                    <h1><?php echo ($action === 'edit' && $editPage) ? '编辑：' . h($editPage['title']) : '页面管理'; ?></h1>
                    <?php if ($action === 'edit' && $editPage): ?>
                        <a href="pages.php" class="btn btn-secondary">返回列表</a>
                    <?php endif; ?>
                </div>

                <?php if ($message): ?>
                    <div class="admin-alert-<?php echo $messageType; ?>">
                        <?php echo h($message); ?>
                    </div>
                <?php endif; ?>

                <?php if ($action === 'edit' && $editPage): ?>
                    <!-- 编辑页面 -->
                    <div class="card">
                        <div class="card-header">
                            编辑「<?php echo h($editPage['title']); ?>」
                            <?php if (isset($pageMeta[$editPage['slug']]['desc'])): ?>
                                <span style="float:right;color:var(--text-muted);font-weight:normal;font-size:0.85rem;"><?php echo h($pageMeta[$editPage['slug']]['desc']); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <form method="post">
                                <div class="form-group">
                                    <label class="form-label">页面内容</label>
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
                                            <button type="button" data-action="mention" title="提及用户">@用户</button>
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
                                            <textarea id="md-editor" class="md-editor-textarea" placeholder="支持 Markdown 语法，输入内容后可切换预览查看效果..." style="min-height:400px;"><?php echo h($editPage['content']); ?></textarea>
                                            <div id="md-preview" class="md-editor-preview-pane"></div>
                                        </div>
                                        <div class="md-editor-hint">支持 Markdown 语法 | <kbd>Tab</kbd> 缩进 | <kbd>Shift+Tab</kbd> 取消缩进</div>
                                    </div>
                                    <input type="hidden" name="content" id="contentInput">
                                    <input type="file" id="md-image-uploader" accept="image/*" style="display:none;">
                                </div>
                                <div class="btn-group">
                                    <button type="submit" class="btn">保存</button>
                                    <a href="pages.php" class="btn btn-secondary">取消</a>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- 页面列表 -->
                    <div class="card">
                        <div class="card-header">
                            站点页面
                            <span style="float:right;color:var(--text-muted);font-weight:normal;font-size:0.85rem;">在此管理关于我们、法律声明等页面内容</span>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>页面</th>
                                            <th>说明</th>
                                            <th>前台链接</th>
                                            <th>更新时间</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pages as $p): ?>
                                            <?php $meta = $pageMeta[$p['slug']] ?? ['title' => $p['title'], 'desc' => '']; ?>
                                            <tr>
                                                <td><strong><?php echo h($meta['title']); ?></strong></td>
                                                <td class="text-muted"><?php echo h($meta['desc']); ?></td>
                                                <td>
                                                    <a href="/page/<?php echo h($p['slug']); ?>" target="_blank" style="font-size:0.85rem;">/page/<?php echo h($p['slug']); ?></a>
                                                </td>
                                                <td style="font-size:0.85rem;"><?php echo $p['updated_at'] ? date('Y-m-d H:i', strtotime($p['updated_at'])) : '-'; ?></td>
                                                <td>
                                                    <a href="?action=edit&slug=<?php echo h($p['slug']); ?>" class="btn btn-sm<?php echo pd('site','edit'); ?>"<?php echo pdAttr('site','edit'); ?>>编辑</a>
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
    <?php if ($action === 'edit' && $editPage): ?>
    <script src="/assets/js/marked.min.js"></script>
    <script src="/assets/js/markdown-editor.js"></script>
    <script>
    MarkdownEditor.init({
        textareaId: 'md-editor',
        previewId: 'md-preview',
        imageUploadUrl: 'pages.php'
    });

    document.querySelector('form[method="post"]').addEventListener('submit', function() {
        var textarea = document.getElementById('md-editor');
        document.getElementById('contentInput').value = textarea.value;
    });
    </script>
    <?php endif; ?>
</body>
</html>

