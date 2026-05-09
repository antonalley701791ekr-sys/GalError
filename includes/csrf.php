<?php
/**
 * CSRF 防护工具
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('csrf_token')) {
    function csrf_token(string $scope = 'default'): string {
        if (!isset($_SESSION['csrf_tokens']) || !is_array($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }

        $row = $_SESSION['csrf_tokens'][$scope] ?? null;
        $now = time();
        $ttl = 7200; // 2小时

        if (!is_array($row) || empty($row['value']) || empty($row['ts']) || ($now - (int)$row['ts']) > $ttl) {
            $row = [
                'value' => bin2hex(random_bytes(32)),
                'ts' => $now,
            ];
            $_SESSION['csrf_tokens'][$scope] = $row;
        }

        return (string)$row['value'];
    }
}

if (!function_exists('csrf_input')) {
    function csrf_input(string $scope = 'default', string $field = '_csrf'): string {
        $token = csrf_token($scope);
        return '<input type="hidden" name="' . h($field) . '" value="' . h($token) . '">';
    }
}

if (!function_exists('csrf_validate')) {
    function csrf_validate(?string $token, string $scope = 'default'): bool {
        if (!is_string($token) || $token === '') {
            return false;
        }

        $row = $_SESSION['csrf_tokens'][$scope] ?? null;
        if (!is_array($row) || empty($row['value']) || empty($row['ts'])) {
            return false;
        }

        $ttl = 7200;
        if ((time() - (int)$row['ts']) > $ttl) {
            return false;
        }

        return hash_equals((string)$row['value'], $token);
    }
}

if (!function_exists('csrf_validate_request')) {
    function csrf_validate_request(string $scope = 'default', string $field = '_csrf'): bool {
        $token = $_POST[$field] ?? '';
        if (!is_string($token) || $token === '') {
            $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        }
        return csrf_validate($token, $scope);
    }
}

if (!function_exists('csrf_reject')) {
    function csrf_reject(): void {
        http_response_code(403);
        echo 'CSRF token 校验失败';
        exit;
    }
}
