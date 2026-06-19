<?php
$footerText = getSiteSetting('footer_text', '');
$markdownTableMobileMtime = @filemtime(BASE_PATH . 'assets/js/markdown-table-mobile.js') ?: time();
echo view('partials/footer.twig', [
    'year' => date('Y'),
    'site_name' => getSiteSetting('site_name', SITE_NAME),
    'footer_text' => $footerText,
    'is_admin' => isAdmin(),
    'site_footer_scripts_html' => function_exists('renderSiteFooterScripts') ? (function () { ob_start(); renderSiteFooterScripts(); return ob_get_clean(); })() : '',
    'assets_ver' => ASSETS_VER,
    'markdown_table_mobile_mtime' => $markdownTableMobileMtime,
    'user_card_js_ver' => ASSETS_VER . '-' . (@filemtime(BASE_PATH . 'assets/js/user-card.js') ?: time()),
    'user_online_ping_js_ver' => ASSETS_VER . '-' . (@filemtime(BASE_PATH . 'assets/js/user-online-ping.js') ?: time()),
    'is_logged_in' => isUserLoggedIn(),
]);
