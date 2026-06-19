<?php
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/config.php';
}

require_once BASE_PATH . 'vendor/autoload.php';

function getTwigEnvironment(): \Twig\Environment
{
    static $twig = null;

    if ($twig === null) {
        $loader = new \Twig\Loader\FilesystemLoader(BASE_PATH . 'templates');
        $twig = new \Twig\Environment($loader, [
            'cache' => BASE_PATH . 'cache/twig',
            'auto_reload' => true,
            'debug' => false,
        ]);

        $twig->addFunction(new \Twig\TwigFunction('csrf_token', static function (string $scope = 'default'): string {
            return function_exists('csrf_token') ? csrf_token($scope) : '';
        }));
        $twig->addFunction(new \Twig\TwigFunction('csrf_input', static function (string $scope = 'default', string $field = '_csrf'): string {
            return function_exists('csrf_input') ? csrf_input($scope, $field) : '';
        }, ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('file_exists', static function (string $path): bool {
            return is_string($path) && $path !== '' && file_exists($path);
        }));
        $twig->addFunction(new \Twig\TwigFunction('hasPermission', static function (string $module, string $action): bool {
            return function_exists('hasPermission') ? hasPermission($module, $action) : false;
        }));
        $twig->addFunction(new \Twig\TwigFunction('isSuperAdmin', static function (): bool {
            return function_exists('isSuperAdmin') ? isSuperAdmin() : false;
        }));
        $twig->addFilter(new \Twig\TwigFilter('json_decode', static function ($value, bool $associative = true) {
            if (is_array($value)) {
                return $value;
            }
            if (!is_string($value) || $value === '') {
                return [];
            }
            $decoded = json_decode($value, $associative);
            return is_array($decoded) ? $decoded : [];
        }));
    }

    return $twig;
}

function renderTwig(string $template, array $data = []): string
{
    $data['_site_name'] = SITE_NAME;
    $data['_assets_ver'] = ASSETS_VER;
    $data['_is_login'] = function_exists('isUserLoggedIn') ? isUserLoggedIn() : false;
    $data['_user_css_ver'] = ASSETS_VER . '-' . (@filemtime(BASE_PATH . 'assets/css/user.css') ?: time());
    $data['_user_card_js_ver'] = ASSETS_VER . '-' . (@filemtime(BASE_PATH . 'assets/js/user-card.js') ?: time());
    $data['_markdown_editor_css_ver'] = ASSETS_VER . '-' . (@filemtime(BASE_PATH . 'assets/css/markdown-editor.css') ?: time());
    $data['_todo_floating_js_ver'] = ASSETS_VER . '-' . (@filemtime(BASE_PATH . 'assets/js/todo-floating.js') ?: time());

    if (str_starts_with($template, 'admin/')) {
        $adminPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $data['page_title'] = $data['page_title'] ?? '';
        $data['admin_css_mtime'] = $data['admin_css_mtime'] ?? (@filemtime(BASE_PATH . 'assets/css/style.css') ?: time());
        $data['_admin_users_css_ver'] = ASSETS_VER . '-' . (@filemtime(BASE_PATH . 'templates/admin/partials/users_cell_actions.twig') ?: time());

        if (!isset($data['admin_head_html'])) {
            ob_start();
            if (function_exists('renderAdminHeadScript')) {
                renderAdminHeadScript();
            }
            $data['admin_head_html'] = ob_get_clean();
        }
        if (!isset($data['header_html'])) {
            ob_start();
            include BASE_PATH . 'includes/header.php';
            $data['header_html'] = ob_get_clean();
        }
        if (!isset($data['sidebar_html'])) {
            ob_start();
            if (function_exists('renderAdminSidebar')) {
                renderAdminSidebar($adminPage);
            }
            $data['sidebar_html'] = ob_get_clean();
        }
        if (!isset($data['footer_scripts_html'])) {
            ob_start();
            if (function_exists('renderAdminFooterScripts')) {
                renderAdminFooterScripts();
            }
            $data['footer_scripts_html'] = ob_get_clean();
        }
    }

    return getTwigEnvironment()->render($template, $data);
}

function view(string $template, array $data = []): void
{
    echo renderTwig($template, $data);
}
