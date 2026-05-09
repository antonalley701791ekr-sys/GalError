    <!-- 页脚 -->
    <footer class="footer">
        <div class="container">
            <div class="footer-links">
                <a href="/page/about">关于我们</a>
                <a href="/page/legal">版权及法律声明</a>
                <a href="/page/entry-guide">入站须知</a>
                <?php if (isAdmin()): ?>
                    <a href="/page/admin-guide">管理员须知</a>
                <?php endif; ?>
            </div>
            <p class="footer-copyright">&copy; <?php echo date('Y'); ?> <?php echo h(getSiteSetting('site_name', SITE_NAME)); ?></p>
            <?php $footerText = getSiteSetting('footer_text', ''); if ($footerText): ?>
                <p class="footer-desc"><?php echo h($footerText); ?></p>
            <?php endif; ?>
        </div>
    </footer>
    <?php renderSiteFooterScripts(); ?>
    <div id="global-image-viewer" class="global-image-viewer-modal" role="dialog" aria-modal="true" aria-label="图片预览">
        <img src="" alt="" class="global-image-viewer-modal-image">
    </div>
    <script src="/assets/js/image-viewer.js?v=<?php echo ASSETS_VER; ?>"></script>
    <script>
    (function() {
        var match = document.cookie.match(/(?:^|; )clear_markdown_draft=([^;]+)/);
        if (!match) return;
        var draftKey = decodeURIComponent(match[1] || '');
        if (draftKey) {
            try {
                localStorage.removeItem(draftKey);
            } catch (e) {}
        }
        document.cookie = 'clear_markdown_draft=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
    })();
    </script>
    <script src="/assets/js/user-dropdown.js?v=<?php echo ASSETS_VER; ?>"></script>
    <?php if (isUserLoggedIn()): ?>
    <script src="/assets/js/user-online-ping.js?v=<?php echo ASSETS_VER; ?>"></script>
    <?php endif; ?>
