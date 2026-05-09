<?php
require_once 'includes/user_auth.php';
require_once 'includes/markdown.php';

$slug = trim($_GET['slug'] ?? '');
$allowedSlugs = ['about', 'legal', 'entry-guide', 'admin-guide'];

if (!$slug || !in_array($slug, $allowedSlugs)) {
    header('Location: /');
    exit;
}

// 管理员须知仅管理员可见
if ($slug === 'admin-guide' && !isAdmin()) {
    header('Location: /');
    exit;
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM site_pages WHERE slug = ?");
$stmt->execute([$slug]);
$page = $stmt->fetch();

if (!$page) {
    header('Location: /');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($page['title']); ?> - <?php echo h(getSiteSetting('site_name', SITE_NAME)); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/user.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/markdown-editor.css">
    <?php renderSiteHead(); ?>
</head>
<body class="has-fixed-nav">
    <?php include 'includes/header.php'; ?>

    <main class="main">
        <div class="container" style="max-width: 900px;">
            <div class="doc-detail-breadcrumb">
                <a href="/">首页</a> &gt; <span><?php echo h($page['title']); ?></span>
            </div>

            <div class="card">
                <div class="card-body">
                    <div class="doc-detail-header">
                        <h1 class="doc-detail-title"><?php echo h($page['title']); ?></h1>
                    </div>
                    <div class="doc-detail-content markdown-body">
                        <?php
                        $content = trim($page['content']);
                        if ($content) {
                            echo md_to_html($content);
                        } else {
                            echo '<p class="text-muted" style="text-align:center;padding:40px 0;">暂无内容</p>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>
</html>
