<?php
require_once dirname(__DIR__) . '/includes/user_auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/api_security.php';
require_once dirname(__DIR__) . '/includes/api_response.php';

header('Content-Type: application/json');

api_require_basic_auth('discussion');
api_enforce_rate_limit('discussion', 60, 60);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'code' => 'method_not_allowed', 'message' => '不支持的请求方法']);
    exit;
}

function isSameOriginApiRequest(): bool {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return false;
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
    return false;
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

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['action'])) {
    echo json_encode(['success' => false, 'code' => 'invalid_params', 'message' => '参数错误']);
    exit;
}

$pdo = getDB();
$action = $input['action'];

if ($action === 'delete') {
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'code' => 'forbidden', 'message' => '无权限']);
        exit;
    }

    $discId = intval($input['id'] ?? 0);
    if ($discId <= 0) {
        echo json_encode(['success' => false, 'code' => 'invalid_id', 'message' => '无效的话题ID']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE discussions SET status = 'deleted' WHERE id = ?");
    $result = $stmt->execute([$discId]);

    echo json_encode([
    'success' => $result ? true : false,
    'code' => $result ? 'ok' : 'db_error'
]);
    exit;
}

echo json_encode(['success' => false, 'code' => 'unknown_action', 'message' => '未知操作']);
