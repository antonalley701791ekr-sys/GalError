<?php
/**
 * 滑块验证码 API 端点
 * GET  /api/captcha.php — 获取新的 challenge
 * POST /api/captcha.php — 验证用户答案
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once dirname(__DIR__) . '/includes/user_auth.php';
require_once dirname(__DIR__) . '/includes/api_response.php';
require_once dirname(__DIR__) . '/includes/api_security.php';

$method = $_SERVER['REQUEST_METHOD'];

api_require_basic_auth('captcha');
api_enforce_rate_limit('captcha', 120, 60);

if ($method === 'GET') {
    $result = generateCaptchaChallenge();
    if (isset($result['error'])) {
        $extra = [];
        if (isset($result['retry_after'])) {
            $extra['retry_after'] = $result['retry_after'];
        }
        api_error('captcha_rate_limited', (string)$result['error'], 429, $extra);
    }
    api_json(array_merge([
        'success' => true,
        'code' => 'ok',
        'message' => 'ok'
    ], $result), 200);
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $token = trim($input['token'] ?? '');
    $x = isset($input['x']) ? (int)$input['x'] : -1;

    if ($token === '' || $x < 0) {
        api_error('invalid_params', '参数不完整', 400);
    }

    $result = verifyCaptchaAnswer($token, $x);
    if (empty($result['success'])) {
        api_json([
            'success' => false,
            'code' => 'captcha_verify_failed',
            'message' => (string)($result['message'] ?? '验证失败')
        ], 200);
    }

    api_json(array_merge([
        'success' => true,
        'code' => 'ok',
        'message' => (string)($result['message'] ?? '验证通过')
    ], $result), 200);
}

api_error('method_not_allowed', '请求方法不允许', 405);
