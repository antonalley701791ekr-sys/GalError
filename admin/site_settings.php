<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

checkLogin();
requirePermission('site', 'view');

$pdo = getDB();
$message = '';
$messageType = '';

// 获取所有站点设置
function getSiteSettings($pdo) {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings");
    $settings = [];
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

// 设置某个key
function setSetting($pdo, $key, $value) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as c FROM site_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    if ($stmt->fetch()['c'] > 0) {
        $pdo->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = ?")->execute([$value, $key]);
    } else {
        $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, $value]);
    }
}

$settings = getSiteSettings($pdo);

// 保存设置
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission('site', 'edit');

    // 网站名称
    setSetting($pdo, 'site_name', trim($_POST['site_name'] ?? ''));

    // 公告横幅
    setSetting($pdo, 'announcement_text', trim($_POST['announcement_text'] ?? ''));
    setSetting($pdo, 'announcement_enabled', isset($_POST['announcement_enabled']) ? '1' : '0');

    // 自定义 footer 文字
    setSetting($pdo, 'footer_text', trim($_POST['footer_text'] ?? ''));

    // SEO 元信息
    setSetting($pdo, 'meta_description', trim($_POST['meta_description'] ?? ''));
    setSetting($pdo, 'meta_keywords', trim($_POST['meta_keywords'] ?? ''));

    // favicon
    if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
        $result = handleSiteImageUpload($_FILES['favicon']);
        if ($result['success']) {
            // 删除旧 favicon
            $old = $settings['favicon'] ?? '';
            if ($old && file_exists(BASE_PATH . $old)) {
                unlink(BASE_PATH . $old);
            }
            setSetting($pdo, 'favicon', $result['path']);
        } else {
            $message = $result['message'];
            $messageType = 'error';
        }
    }

    // Logo 上传
    if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
        $result = handleSiteImageUpload($_FILES['site_logo']);
        if ($result['success']) {
            $old = $settings['site_logo'] ?? '';
            if ($old && file_exists(BASE_PATH . $old)) unlink(BASE_PATH . $old);
            setSetting($pdo, 'site_logo', $result['path']);
        } else {
            $message = $result['message'];
            $messageType = 'error';
        }
    }
    // 删除 Logo
    if (isset($_POST['delete_logo']) && !empty($settings['site_logo'])) {
        $old = $settings['site_logo'];
        if (file_exists(BASE_PATH . $old)) unlink(BASE_PATH . $old);
        setSetting($pdo, 'site_logo', '');
    }

    // 夜间模式背景图上传
    if (isset($_FILES['site_bg']) && $_FILES['site_bg']['error'] === UPLOAD_ERR_OK) {
        $result = handleSiteImageUpload($_FILES['site_bg']);
        if ($result['success']) {
            $old = $settings['site_bg'] ?? '';
            if ($old && file_exists(BASE_PATH . $old)) unlink(BASE_PATH . $old);
            setSetting($pdo, 'site_bg', $result['path']);
        } else {
            $message = $result['message'];
            $messageType = 'error';
        }
    }
    // 删除夜间模式背景图
    if (isset($_POST['delete_bg']) && !empty($settings['site_bg'])) {
        $old = $settings['site_bg'];
        if (file_exists(BASE_PATH . $old)) unlink(BASE_PATH . $old);
        setSetting($pdo, 'site_bg', '');
    }

    // 日间模式背景图上传
    if (isset($_FILES['site_bg_light']) && $_FILES['site_bg_light']['error'] === UPLOAD_ERR_OK) {
        $result = handleSiteImageUpload($_FILES['site_bg_light']);
        if ($result['success']) {
            $old = $settings['site_bg_light'] ?? '';
            if ($old && file_exists(BASE_PATH . $old)) unlink(BASE_PATH . $old);
            setSetting($pdo, 'site_bg_light', $result['path']);
        } else {
            $message = $result['message'];
            $messageType = 'error';
        }
    }
    // 删除日间模式背景图
    if (isset($_POST['delete_bg_light']) && !empty($settings['site_bg_light'])) {
        $old = $settings['site_bg_light'];
        if (file_exists(BASE_PATH . $old)) unlink(BASE_PATH . $old);
        setSetting($pdo, 'site_bg_light', '');
    }

    // 自定义 CSS
    setSetting($pdo, 'custom_css', trim($_POST['custom_css'] ?? ''));

    if ($messageType !== 'error') {
        $message = '设置已保存';
        $messageType = 'success';
        $settings = getSiteSettings($pdo);
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>站点外观 - <?php echo h(SITE_NAME); ?> 管理后台</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <?php renderAdminHeadScript(); ?>
</head>
<body class="has-fixed-nav">
    <?php include '../includes/header.php'; ?>
    <div class="admin-layout">
        <?php renderAdminSidebar('site_settings.php'); ?>

        <div class="admin-content">
            <main class="admin-main">
                <div class="admin-page-header">
                    <h1>站点外观</h1>
                </div>
                <?php if ($message): ?>
                    <div class="admin-alert-<?php echo $messageType; ?>">
                        <?php echo h($message); ?>
                    </div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <?php $canEdit = hasPermission('site', 'edit'); ?>
                    <?php if (!$canEdit): ?>
                    <div class="admin-alert-warning" style="margin-bottom: 20px;">
                        您没有编辑权限，当前为只读模式。
                    </div>
                    <?php endif; ?>
                    <!-- 网站名称 -->
                    <div class="card settings-section">
                        <div class="card-header">网站名称</div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="form-label">网站名称</label>
                                <input type="text" name="site_name" class="form-input" value="<?php echo h($settings['site_name'] ?? ''); ?>" placeholder="<?php echo h(SITE_NAME); ?>">
                                <p class="form-hint">用于前台导航栏和后台侧边栏显示，留空则使用默认名称「<?php echo h(SITE_NAME); ?>」</p>
                            </div>
                        </div>
                    </div>

                    <!-- 网站 Logo -->
                    <div class="card settings-section">
                        <div class="card-header">网站 Logo</div>
                        <div class="card-body">
                            <?php if (!empty($settings['site_logo'])): ?>
                                <div style="margin-bottom: 12px; display: flex; align-items: center; gap: 12px;">
                                    <img src="/<?php echo h($settings['site_logo']); ?>" alt="当前 Logo" style="height: 40px; width: auto; border-radius: 4px; border: 1px solid var(--glass-border); padding: 4px; background: var(--glass-bg);">
                                    <span class="text-muted" style="font-size: 13px;">当前 Logo</span>
                                </div>
                            <?php endif; ?>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <input type="file" name="site_logo" class="form-input" accept="image/*" style="max-width: 400px;">
                                <?php if (!empty($settings['site_logo'])): ?>
                                    <label style="cursor: pointer; font-weight: normal; display: flex; align-items: center; gap: 4px;">
                                        <input type="checkbox" name="delete_logo" value="1"> <span class="text-muted" style="font-size: 13px;">删除 Logo</span>
                                    </label>
                                <?php endif; ?>
                            </div>
                            <p class="form-hint">显示在前台导航栏和后台侧边栏站点名称左侧，支持 PNG/SVG/ICO 等格式，建议高度 40px</p>
                        </div>
                    </div>

                    <!-- 前台背景图 -->
                    <div class="card settings-section">
                        <div class="card-header">前台背景图</div>
                        <div class="card-body">
                            <p class="form-hint" style="margin-bottom: 16px;">日间模式和夜间模式可分别设置不同的背景图。未设置背景图的主题将显示默认渐变背景。</p>

                            <!-- 夜间模式背景图 -->
                            <div style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid var(--glass-border);">
                                <label class="form-label" style="font-weight: 600; margin-bottom: 10px; display: block;">夜间模式背景图</label>
                                <?php if (!empty($settings['site_bg'])): ?>
                                    <div style="margin-bottom: 12px;">
                                        <img src="/<?php echo h($settings['site_bg']); ?>" alt="夜间模式背景图" style="max-width: 300px; max-height: 180px; border-radius: 6px; border: 1px solid var(--glass-border); object-fit: cover;">
                                    </div>
                                <?php endif; ?>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <input type="file" name="site_bg" class="form-input" accept="image/*" style="max-width: 400px;">
                                    <?php if (!empty($settings['site_bg'])): ?>
                                        <label style="cursor: pointer; font-weight: normal; display: flex; align-items: center; gap: 4px;">
                                            <input type="checkbox" name="delete_bg" value="1"> <span class="text-muted" style="font-size: 13px;">删除</span>
                                        </label>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- 日间模式背景图 -->
                            <div>
                                <label class="form-label" style="font-weight: 600; margin-bottom: 10px; display: block;">日间模式背景图</label>
                                <?php if (!empty($settings['site_bg_light'])): ?>
                                    <div style="margin-bottom: 12px;">
                                        <img src="/<?php echo h($settings['site_bg_light']); ?>" alt="日间模式背景图" style="max-width: 300px; max-height: 180px; border-radius: 6px; border: 1px solid var(--glass-border); object-fit: cover;">
                                    </div>
                                <?php endif; ?>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <input type="file" name="site_bg_light" class="form-input" accept="image/*" style="max-width: 400px;">
                                    <?php if (!empty($settings['site_bg_light'])): ?>
                                        <label style="cursor: pointer; font-weight: normal; display: flex; align-items: center; gap: 4px;">
                                            <input type="checkbox" name="delete_bg_light" value="1"> <span class="text-muted" style="font-size: 13px;">删除</span>
                                        </label>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <p class="form-hint" style="margin-top: 12px;">支持 JPG/PNG 格式，建议宽度 1920px 以上。背景固定不随页面滚动。</p>
                        </div>
                    </div>

                    <!-- 公告横幅 -->
                    <div class="card settings-section">
                        <div class="card-header">公告横幅</div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="toggle-label">
                                    <input type="checkbox" name="announcement_enabled" value="1" class="toggle-checkbox" id="announcementToggle" <?php echo ($settings['announcement_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                    <span class="toggle-switch"></span>
                                    <span>启用公告横幅</span>
                                </label>
                            </div>
                            <div class="form-group">
                                <label class="form-label">公告文字</label>
                                <textarea name="announcement_text" class="form-textarea" rows="3" id="announcementText" placeholder="例如：网站维护中，部分功能可能受限"><?php echo h($settings['announcement_text'] ?? ''); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label">预览效果</label>
                                <div class="announcement-preview" id="announcementPreview">
                                    <span class="announcement-preview-icon"></span>
                                    <span class="announcement-preview-text"><?php echo h($settings['announcement_text'] ?? ''); ?></span>
                                </div>
                                <p class="form-hint">预览仅供参考，实际效果以前台页面为准</p>
                            </div>
                        </div>
                    </div>

                    <!-- SEO 设置 -->
                    <div class="card settings-section">
                        <div class="card-header">SEO 设置</div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="form-label">页面描述（meta description）</label>
                                <textarea name="meta_description" class="form-textarea" rows="2"><?php echo h($settings['meta_description'] ?? ''); ?></textarea>
                            </div>
                            <div class="form-group">
                                <label class="form-label">关键词（meta keywords）</label>
                                <input type="text" name="meta_keywords" class="form-input" value="<?php echo h($settings['meta_keywords'] ?? ''); ?>" placeholder="逗号分隔关键词">
                            </div>
                        </div>
                    </div>

                    <!-- Favicon -->
                    <div class="card settings-section">
                        <div class="card-header">Favicon</div>
                        <div class="card-body">
                            <?php if (!empty($settings['favicon'])): ?>
                                <div class="image-preview" style="margin-bottom: 10px;">
                                    <img src="/<?php echo h($settings['favicon']); ?>" alt="当前 Favicon" style="max-width: 48px; max-height: 48px;">
                                </div>
                            <?php endif; ?>
                            <input type="file" name="favicon" class="form-input" accept="image/*" style="max-width: 400px;">
                            <p class="form-hint">支持 ICO/PNG/SVG 格式</p>
                        </div>
                    </div>

                    <!-- 页脚文字 -->
                    <div class="card settings-section">
                        <div class="card-header">页脚文字</div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="form-label">自定义页脚</label>
                                <input type="text" name="footer_text" class="form-input" value="<?php echo h($settings['footer_text'] ?? ''); ?>" placeholder="留空使用默认页脚">
                            </div>
                        </div>
                    </div>

                    <!-- 自定义 CSS -->
                    <div class="card settings-section">
                        <div class="card-header">自定义 CSS</div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="form-label">自定义样式</label>
                                <textarea name="custom_css" class="form-textarea" rows="8" style="font-family: monospace; font-size: 13px;"><?php echo h($settings['custom_css'] ?? ''); ?></textarea>
                                <p class="form-hint">在前台页面中注入的自定义 CSS 代码</p>
                            </div>
                        </div>
                    </div>

                    <div style="margin-bottom: 40px;">
                        <button type="submit" class="btn<?php echo pd('site','edit'); ?>"<?php echo pdBtnAttr('site','edit'); ?>>保存设置</button>
                    </div>
                </form>
            </main>
        </div>
    </div>
    <?php renderAdminFooterScripts(); ?>
    <script>
    (function() {
        var text = document.getElementById('announcementText');
        var preview = document.getElementById('announcementPreview');
        var previewText = preview.querySelector('.announcement-preview-text');
        var toggle = document.getElementById('announcementToggle');

        function updatePreview() {
            var val = text.value.trim();
            previewText.textContent = val || '公告内容为空';
            preview.classList.toggle('is-empty', !val);
            preview.classList.toggle('is-disabled', !toggle.checked);
        }

        text.addEventListener('input', updatePreview);
        toggle.addEventListener('change', updatePreview);
        updatePreview();
    })();
    </script>
</body>
</html>

