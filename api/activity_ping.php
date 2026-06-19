<?php
require_once dirname(__DIR__) . '/includes/user_auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/api_security.php';
require_once dirname(__DIR__) . '/includes/api_response.php';

header('Content-Type: application/json; charset=utf-8');

api_require_basic_auth('activity_ping');
api_enforce_rate_limit('activity_ping', 180, 60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'code' => 'method_not_allowed', 'message' => '请求方法不允许']);
    exit;
}

function isSameOriginApiRequest(): bool {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return true;
    }
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '') {
        $originHost = parse_url($origin, PHP_URL_HOST);
        return is_string($originHost) && strcasecmp($originHost, $host) === 0;
    }
    $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($referer !== '') {
        $refererHost = parse_url($referer, PHP_URL_HOST);
        return is_string($refererHost) && strcasecmp($refererHost, $host) === 0;
    }
    return true;
}

if (!isSameOriginApiRequest()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'code' => 'bad_origin', 'message' => '非法来源']);
    exit;
}

if (!csrf_validate_request('default')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'code' => 'csrf_failed', 'message' => 'CSRF token 校验失败']);
    exit;
}

if (!isUserLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'code' => 'unauthorized', 'message' => '未登录']);
    exit;
}

updateCurrentUserActivity(false);

echo json_encode([
    'success' => true,
    'code' => 'ok',
    'server_now_ts' => time(),
]);
