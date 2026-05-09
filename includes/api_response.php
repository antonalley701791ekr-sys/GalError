<?php
/**
 * API 响应工具
 * 统一 JSON 结构，便于前端与日志处理。
 */

if (!function_exists('api_json')) {
    function api_json(array $payload, int $status = 200): void {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('api_error')) {
    function api_error(string $code, string $message, int $status = 400, array $extra = []): void {
        $payload = array_merge([
            'success' => false,
            'code' => $code,
            'message' => $message,
        ], $extra);
        api_json($payload, $status);
    }
}

if (!function_exists('api_success')) {
    function api_success(array $data = [], string $message = 'ok', array $extra = []): void {
        $payload = array_merge([
            'success' => true,
            'code' => 'ok',
            'message' => $message,
        ], $data, $extra);
        api_json($payload, 200);
    }
}
