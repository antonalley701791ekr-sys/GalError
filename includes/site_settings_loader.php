<?php
/**
 * 前台站点设置加载器
 * 在前台页面顶部 require 此文件，即可使用 $siteSettings 和相关辅助函数
 */

require_once __DIR__ . '/config.php';

// 加载所有站点设置
$_siteSettingsCache = null;
function loadSiteSettings() {
    global $_siteSettingsCache;
    if ($_siteSettingsCache !== null) {
        return $_siteSettingsCache;
    }
    try {
        $pdo = getDB();
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings");
        $_siteSettingsCache = [];
        foreach ($stmt->fetchAll() as $row) {
            $_siteSettingsCache[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Exception $e) {
        $_siteSettingsCache = [];
    }
    return $_siteSettingsCache;
}

function getSiteSetting($key, $default = '') {
    $settings = loadSiteSettings();
    return isset($settings[$key]) && $settings[$key] !== '' ? $settings[$key] : $default;
}

// 加载进行中的待办
function getPublicTodos() {
    try {
        $pdo = getDB();
        return $pdo->query("SELECT title, description, status FROM todos WHERE status IN ('pending', 'completed') ORDER BY sort_order ASC, created_at DESC")->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// 渲染公告横幅
function renderAnnouncement() {
    $enabled = getSiteSetting('announcement_enabled', '0');
    $text = getSiteSetting('announcement_text', '');
    if ($enabled === '1' && $text) {
        echo '<div class="announcement-bar">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

// 渲染 head 中的 SEO 和 favicon
function renderSiteHead() {
    $desc = getSiteSetting('meta_description', '');
    $keywords = getSiteSetting('meta_keywords', '');
    $favicon = getSiteSetting('favicon', '');
    $customCss = getSiteSetting('custom_css', '');

    if ($desc) {
        echo '<meta name="description" content="' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }
    if ($keywords) {
        echo '<meta name="keywords" content="' . htmlspecialchars($keywords, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }
    if ($favicon) {
        echo '<link rel="icon" href="/' . htmlspecialchars($favicon, ENT_QUOTES, 'UTF-8') . '">' . "\n";
    }
    if ($customCss) {
        echo '<style>' . $customCss . '</style>' . "\n";
    }
    // FOUC prevention: apply saved theme before CSS loads
    echo '<script>(function(){var t;try{t=localStorage.getItem("galerror-theme")}catch(e){}if(!t){t=window.matchMedia&&window.matchMedia("(prefers-color-scheme:light)").matches?"light":"dark"}document.documentElement.setAttribute("data-theme",t)})()</script>' . "\n";

    // 自定义背景图（日间/夜间模式独立设置）
    // 背景图 + 半透明遮罩 + 模糊效果，替换默认渐变+网格动画
    $siteBgDark = getSiteSetting('site_bg', '');
    $siteBgLight = getSiteSetting('site_bg_light', '');

    if ($siteBgDark || $siteBgLight) {
        $css = '';

        if ($siteBgDark) {
            $darkUrl = htmlspecialchars($siteBgDark, ENT_QUOTES, 'UTF-8');
            // 夜间模式：自定义背景图 + 暗色遮罩
            $css .= 'body::before{background:url(/' . $darkUrl . ') center/cover no-repeat fixed !important}';
            $css .= 'body::after{background-image:none !important;animation:none !important;background:rgba(15,17,23,0.72) !important;backdrop-filter:blur(12px) !important;-webkit-backdrop-filter:blur(12px) !important}';
        }

        if ($siteBgLight) {
            $lightUrl = htmlspecialchars($siteBgLight, ENT_QUOTES, 'UTF-8');
            // 日间模式：自定义背景图 + 亮色遮罩
            $css .= '[data-theme="light"] body::before{background:url(/' . $lightUrl . ') center/cover no-repeat fixed !important}';
            $css .= '[data-theme="light"] body::after{background-image:none !important;animation:none !important;background:rgba(245,245,247,0.78) !important;backdrop-filter:blur(12px) !important;-webkit-backdrop-filter:blur(12px) !important}';
        } else if ($siteBgDark) {
            // 仅有夜间背景图：日间模式恢复默认渐变背景（覆盖掉夜间的 url）
            $css .= '[data-theme="light"] body::before{background:radial-gradient(ellipse at 20% 50%,rgba(124,58,237,0.06) 0%,transparent 55%),radial-gradient(ellipse at 80% 20%,rgba(219,39,119,0.04) 0%,transparent 50%),radial-gradient(ellipse at 50% 80%,rgba(8,145,178,0.04) 0%,transparent 50%),var(--bg-primary) !important}';
            $css .= '[data-theme="light"] body::after{background-image:linear-gradient(rgba(124,58,237,0.04) 1px,transparent 1px),linear-gradient(90deg,rgba(124,58,237,0.04) 1px,transparent 1px) !important;background:transparent !important;backdrop-filter:none !important;-webkit-backdrop-filter:none !important;animation:gridMove 20s linear infinite !important}';
        }

        echo '<style>' . $css . '</style>' . "\n";
    }
}

// 渲染前台页脚脚本（theme.js）
function renderSiteFooterScripts() {
    echo '<script src="/assets/js/theme.js"></script>' . "\n";
}

// 渲染页脚文字
function getSiteFooterText() {
    $custom = getSiteSetting('footer_text', '');
    if ($custom) {
        return htmlspecialchars($custom, ENT_QUOTES, 'UTF-8');
    }
    return '&copy; ' . date('Y') . ' ' . htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') . ' - 专注于 Galgame 报错解决方案';
}

// 获取已启用的文档（轮播用）
function getEnabledDocuments() {
    try {
        $pdo = getDB();
        $stmt = $pdo->query("SELECT * FROM documents WHERE enabled = 1 ORDER BY sort_order ASC, id DESC");
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

// 渲染文档管理
function renderDocumentCarousel() {
    $docs = getEnabledDocuments();
    if (empty($docs)) return;

    $h = function($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $count = count($docs);

    echo '<section class="doc-carousel-section">';
    echo '<div class="doc-carousel" data-count="' . $count . '">';
    echo '<div class="doc-carousel-track">';

    foreach ($docs as $idx => $doc) {
        $href = $doc['link'] ? $h($doc['link']) : '/document/' . $doc['id'];
        $target = $doc['link'] ? ' target="_blank" rel="noopener"' : '';
        $loadingAttr = $idx === 0 ? '' : ' loading="lazy"';
        echo '<div class="doc-carousel-slide">';
        echo '<a href="' . $href . '"' . $target . ' class="doc-carousel-link">';
        if ($doc['image']) {
            echo '<img src="' . $h($doc['image']) . '" alt="' . $h($doc['title']) . '" class="doc-carousel-bg"' . $loadingAttr . '>';
        }
        echo '<div class="doc-carousel-overlay"></div>';
        echo '<div class="doc-carousel-info">';
        echo '<h3 class="doc-carousel-title">' . $h($doc['title']) . '</h3>';
        if ($doc['description']) {
            echo '<p class="doc-carousel-desc">' . $h($doc['description']) . '</p>';
        }
        echo '</div>';
        echo '</a>';
        echo '</div>';
    }

    echo '</div>'; // track

    // 箭头按钮
    if ($count > 1) {
        echo '<button class="doc-carousel-arrow doc-carousel-prev" aria-label="上一张"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg></button>';
        echo '<button class="doc-carousel-arrow doc-carousel-next" aria-label="下一张"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 6 15 12 9 18"/></svg></button>';

        // 圆点指示器
        echo '<div class="doc-carousel-dots">';
        for ($i = 0; $i < $count; $i++) {
            echo '<button class="doc-carousel-dot' . ($i === 0 ? ' active' : '') . '" data-index="' . $i . '" aria-label="第' . ($i + 1) . '张"></button>';
        }
        echo '</div>';
    }

    echo '</div>'; // carousel
    echo '</section>';
    if ($count > 1) {
        echo '<script src="/assets/js/carousel.js"></script>' . "\n";
    }
}
