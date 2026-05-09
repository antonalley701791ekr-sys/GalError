<?php
/**
 * 滑块验证码 API 端点
 * GET  /api/captcha.php — 获取新的 challenge
 * POST /api/captcha.php — 验证用户答案
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once dirname(__DIR__) . '/includes/user_auth.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // 生成新的 challenge
    $result = generateCaptchaChallenge();
    if (isset($result['error'])) {
        http_response_code(429);
        $response = ['success' => false, 'message' => $result['error']];
        if (isset($result['retry_after'])) {
            $response['retry_after'] = $result['retry_after'];
        }
        echo json_encode($response);
    } else {
        echo json_encode($result);
    }
    exit;
}

if ($method === 'POST') {
    // 验证答案
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $token = trim($input['token'] ?? '');
    $x = isset($input['x']) ? (int)$input['x'] : -1;

    if (empty($token) || $x < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        exit;
    }

    $result = verifyCaptchaAnswer($token, $x);
    if (!$result['success']) {
        http_response_code(200); // 验证失败仍返回 200，通过 success 字段区分
    }
    echo json_encode($result);
    exit;
}

// 其他方法
http_response_code(405);
echo json_encode(['success' => false, 'message' => '请求方法不允许']);
