<?php
require_once dirname(__DIR__) . '/includes/user_auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/api_response.php';
require_once dirname(__DIR__) . '/includes/api_security.php';
require_once dirname(__DIR__) . '/includes/markdown.php';
require_once dirname(__DIR__) . '/includes/sensitive_filter.php';

header('Content-Type: application/json');

api_require_basic_auth('comment');
api_enforce_rate_limit('comment', 90, 60);

if (!function_exists('normalizeCommentIdempotencyKey')) {
    function normalizeCommentIdempotencyKey($value): string {
        $key = is_string($value) ? trim($value) : '';
        if ($key === '') {
            return '';
        }
        return preg_match('/^[A-Za-z0-9_-]{8,120}$/', $key) ? $key : '';
    }
}

if (!function_exists('getCommentIdempotencyStore')) {
    function getCommentIdempotencyStore(): array {
        if (!isset($_SESSION['comment_idempotency']) || !is_array($_SESSION['comment_idempotency'])) {
            $_SESSION['comment_idempotency'] = [];
        }

        $now = time();
        foreach ($_SESSION['comment_idempotency'] as $key => $row) {
            $ts = is_array($row) ? (int)($row['ts'] ?? 0) : 0;
            if ($ts <= 0 || ($now - $ts) > 1800) {
                unset($_SESSION['comment_idempotency'][$key]);
            }
        }

        return $_SESSION['comment_idempotency'];
    }
}

if (!function_exists('findCommentIdempotencyResult')) {
    function findCommentIdempotencyResult(string $key): ?array {
        if ($key === '') {
            return null;
        }
        $store = getCommentIdempotencyStore();
        if (!isset($store[$key]) || !is_array($store[$key])) {
            return null;
        }
        $row = $store[$key];
        if (empty($row['comment']) || !is_array($row['comment'])) {
            return null;
        }
        return $row;
    }
}

if (!function_exists('saveCommentIdempotencyResult')) {
    function saveCommentIdempotencyResult(string $key, array $comment, string $redirectUrl): void {
        if ($key === '') {
            return;
        }
        getCommentIdempotencyStore();
        $_SESSION['comment_idempotency'][$key] = [
            'ts' => time(),
            'comment' => $comment,
            'redirect_url' => $redirectUrl,
        ];
    }
}

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

function comment_request_token(array $input = []): string {
    $token = '';
    if (isset($input['_csrf']) && is_string($input['_csrf'])) {
        $token = trim($input['_csrf']);
    }
    if ($token === '' && isset($_POST['_csrf']) && is_string($_POST['_csrf'])) {
        $token = trim($_POST['_csrf']);
    }
    if ($token === '' && isset($_SERVER['HTTP_X_CSRF_TOKEN']) && is_string($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $token = trim($_SERVER['HTTP_X_CSRF_TOKEN']);
    }
    if ($token === '') {
        $fallback = $_POST['_csrf_header_token'] ?? '';
        if (is_string($fallback)) {
            $token = trim($fallback);
        }
    }
    return $token;
}

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);
if (!is_array($input)) {
    $input = [];
}
$commentCsrfToken = comment_request_token($input);
$csrfOk = false;
if ($commentCsrfToken !== '') {
    $csrfOk = csrf_validate($commentCsrfToken, 'default') || csrf_validate($commentCsrfToken, 'admin_form');
}

if (!$csrfOk) {
    http_response_code(403);
    echo json_encode(['success' => false, 'code' => 'csrf_failed', 'message' => 'CSRF token 校验失败']);
    exit;
}

$action = '';

if (is_array($input) && isset($input['action'])) {
    $action = (string)$input['action'];
} elseif (isset($_POST['action'])) {
    $action = (string)$_POST['action'];
    $input = $_POST;
} else {
    echo json_encode(['success' => false, 'code' => 'invalid_params', 'message' => '参数错误']);
    exit;
}

$pdo = getDB();

if ($action === 'upload_image') {
    if (!isUserLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'code' => 'unauthorized', 'message' => '请先登录']);
        exit;
    }

    if (isUserBanned()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'code' => 'forbidden', 'message' => '您的账号已被封禁']);
        exit;
    }

    if (!isset($_FILES['image'])) {
        echo json_encode(['success' => false, 'code' => 'missing_file', 'message' => '未选择图片']);
        exit;
    }

    $file = $_FILES['image'];
    if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['size'])) {
        echo json_encode(['success' => false, 'code' => 'invalid_file', 'message' => '上传文件无效']);
        exit;
    }

    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'code' => 'upload_error', 'message' => '上传失败，请重试']);
        exit;
    }

    $maxSize = defined('MAX_FILE_SIZE') ? (int)MAX_FILE_SIZE : (2 * 1024 * 1024);
    if ((int)$file['size'] <= 0 || (int)$file['size'] > $maxSize) {
        echo json_encode(['success' => false, 'code' => 'file_too_large', 'message' => '图片大小不能超过 2MB']);
        exit;
    }

    $tmpPath = (string)$file['tmp_name'];
    if (!is_uploaded_file($tmpPath)) {
        echo json_encode(['success' => false, 'code' => 'invalid_upload', 'message' => '无效的上传文件']);
        exit;
    }

    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false) {
        echo json_encode(['success' => false, 'code' => 'invalid_image', 'message' => '仅支持图片文件']);
        exit;
    }

    $mime = strtolower((string)($imageInfo['mime'] ?? ''));
    $extMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    if (!isset($extMap[$mime])) {
        echo json_encode(['success' => false, 'code' => 'unsupported_type', 'message' => '仅支持 JPG/PNG/GIF/WEBP']);
        exit;
    }

    $targetDir = BASE_PATH . UPLOAD_PATH . 'comment_images/';
    if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
        echo json_encode(['success' => false, 'code' => 'mkdir_failed', 'message' => '创建目录失败']);
        exit;
    }

    $filename = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $extMap[$mime];
    $targetPath = $targetDir . $filename;

    if (!@move_uploaded_file($tmpPath, $targetPath)) {
        echo json_encode(['success' => false, 'code' => 'save_failed', 'message' => '图片保存失败']);
        exit;
    }

    $relativePath = UPLOAD_PATH . 'comment_images/' . $filename;
    $url = '/' . $relativePath;

    echo json_encode([
        'success' => true,
        'url' => $url,
        'path' => $relativePath,
        'markdown' => '![](' . $url . ')',
    ]);
    exit;
}

// ========== 创建评论 ==========
if ($action === 'create') {
    if (!isUserLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'code' => 'unauthorized', 'message' => '请先登录']);
        exit;
    }

    if (isUserBanned()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '您的账号已被封禁']);
        exit;
    }

    $contentType = $input['content_type'] ?? '';
    $contentId = intval($input['content_id'] ?? 0);
    $content = (string)($input['content'] ?? '');
    $parentId = isset($input['parent_id']) ? intval($input['parent_id']) : null;
    $parentComment = null;
    $idempotencyKey = normalizeCommentIdempotencyKey($input['idempotency_key'] ?? '');

    $content = str_replace(["\r\n", "\r"], "\n", $content);

    if (!in_array($contentType, ['discussion', 'error', 'article'], true)) {
        echo json_encode(['success' => false, 'message' => '无效的内容类型']);
        exit;
    }
    if ($contentId <= 0) {
        echo json_encode(['success' => false, 'message' => '无效的内容ID']);
        exit;
    }
    if (trim($content) === '') {
        echo json_encode(['success' => false, 'message' => '评论内容不能为空']);
        exit;
    }
    if (mb_strlen($content) > 5000) {
        echo json_encode(['success' => false, 'message' => '评论内容不能超过5000字']);
        exit;
    }

    if ($contentType === 'discussion') {
        $stmt = $pdo->prepare("SELECT id FROM discussions WHERE id = ? AND status = 'active'");
        $stmt->execute([$contentId]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => '话题不存在或已删除']);
            exit;
        }
    } elseif ($contentType === 'error') {
        $stmt = $pdo->prepare("SELECT id FROM errors WHERE id = ? AND status = 'approved'");
        $stmt->execute([$contentId]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => '报错不存在或未通过审核']);
            exit;
        }
    } elseif ($contentType === 'article') {
        $stmt = $pdo->prepare("SELECT id FROM articles WHERE id = ? AND status = 'approved'");
        $stmt->execute([$contentId]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => '文章不存在或未通过审核']);
            exit;
        }
    }

    if ($parentId && $parentId > 0) {
        $stmt = $pdo->prepare("SELECT id, user_id, content_type, content_id, content FROM comments WHERE id = ? AND status = 'active'");
        $stmt->execute([$parentId]);
        $parentComment = $stmt->fetch();
        if (!$parentComment || $parentComment['content_type'] !== $contentType || (int)$parentComment['content_id'] !== $contentId) {
            echo json_encode(['success' => false, 'message' => '回复的评论不存在']);
            exit;
        }
    } else {
        $parentId = null;
    }

    if ($idempotencyKey !== '') {
        $existingResult = findCommentIdempotencyResult($idempotencyKey);
        if ($existingResult) {
            echo json_encode([
                'success' => true,
                'comment' => $existingResult['comment'],
                'redirect_url' => $existingResult['redirect_url'] ?? null,
                'deduplicated' => true,
            ]);
            exit;
        }
    }

    $userId = getCurrentUserId();
    $rateCheck = checkRateLimit($userId, 'comment');
    if (!$rateCheck['allowed']) {
        echo json_encode(['success' => false, 'code' => 'rate_limited', 'message' => '评论过于频繁，请等待 ' . $rateCheck['wait_seconds'] . ' 秒后再试']);
        exit;
    }

    $sensitiveCheck = containsSensitiveWord($content, [
        'scene' => '评论回复',
        'page' => $contentType . ':' . $contentId,
    ]);
    if ($sensitiveCheck['found']) {
        echo json_encode(['success' => false, 'message' => '评论包含违规内容，请修改后重新提交']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO comments (content_type, content_id, user_id, parent_id, content, status) VALUES (?, ?, ?, ?, ?, 'active')");
    $result = $stmt->execute([$contentType, $contentId, $userId, $parentId, $content]);

    if ($result) {
        $commentId = $pdo->lastInsertId();
        $commentTargetUrl = buildCommentTargetUrl($contentType, $contentId, $commentId);
        recordRateLimit($userId, 'comment');

        $stmt = $pdo->prepare("SELECT username, avatar FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        $contentHtml = md_to_html($content);

        $avatarUrl = '';
        if ($user['avatar'] && file_exists(BASE_PATH . $user['avatar'])) {
            $avatarUrl = '/' . $user['avatar'];
        }

        $commenterName = $user['username'];
        $mentionContextLabel = '评论';
        $mentionContextTitle = '相关内容';

        $authorId = null;
        if ($contentType === 'discussion') {
            $stmt = $pdo->prepare("SELECT user_id, title FROM discussions WHERE id = ?");
            $stmt->execute([$contentId]);
            $target = $stmt->fetch();
            if ($target) {
                $mentionContextLabel = '话题评论';
                $mentionContextTitle = $target['title'] ?? '相关话题';
            }
            if ($target && (int)$target['user_id'] !== $userId) {
                $authorId = (int)$target['user_id'];
                sendNotification($authorId, '您的话题收到了新评论',
                    $commenterName . ' 评论了您的话题「' . mb_substr($target['title'], 0, 30) . '」',
                    $commentTargetUrl);
            }
        } elseif ($contentType === 'error') {
            $stmt = $pdo->prepare("SELECT user_id, title FROM errors WHERE id = ?");
            $stmt->execute([$contentId]);
            $target = $stmt->fetch();
            if ($target) {
                $mentionContextLabel = '报错评论';
                $mentionContextTitle = $target['title'] ?? '相关报错';
            }
            if ($target && $target['user_id'] && (int)$target['user_id'] !== $userId) {
                $authorId = (int)$target['user_id'];
                sendNotification($authorId, '您的报错收到了新评论',
                    $commenterName . ' 评论了您的报错「' . mb_substr($target['title'], 0, 30) . '」',
                    $commentTargetUrl);
            }
        } elseif ($contentType === 'article') {
            $stmt = $pdo->prepare("SELECT user_id, title FROM articles WHERE id = ?");
            $stmt->execute([$contentId]);
            $target = $stmt->fetch();
            if ($target) {
                $mentionContextLabel = '文章评论';
                $mentionContextTitle = $target['title'] ?? '相关文章';
            }
            if ($target && $target['user_id'] && (int)$target['user_id'] !== $userId) {
                $authorId = (int)$target['user_id'];
                sendNotification($authorId, '您的文章收到了新评论',
                    $commenterName . ' 评论了您的文章「' . mb_substr($target['title'], 0, 30) . '」',
                    $commentTargetUrl);
            }
        }

        if ($parentComment && (int)$parentComment['user_id'] !== $userId
            && (!$authorId || (int)$parentComment['user_id'] !== $authorId)) {
            sendNotification((int)$parentComment['user_id'], '有人回复了您的评论',
                $commenterName . ' 回复了您的评论',
                $commentTargetUrl);
        }

        // 显式 @提及 只排除评论作者本人，不再因为“作者通知/回复通知”而跳过。
        // 否则当内容作者或被回复者正好被 @ 时，会收不到 mention 通知。
        sendMentionNotifications($userId, $commenterName, $content, $mentionContextLabel, $mentionContextTitle, $commentTargetUrl);

        $replyToUsername = null;
        $replyToUserId = null;
        $replyToContent = null;
        if ($parentId && $parentComment) {
            $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id = ?");
            $stmt->execute([$parentComment['user_id']]);
            $parentUser = $stmt->fetch();
            if ($parentUser) {
                $replyToUserId = (int)$parentUser['id'];
                $replyToUsername = $parentUser['username'];
            }
            $replyToContent = $parentComment['content'] ?? '';
        }

        $responseComment = [
            'id' => (int)$commentId,
            'user_id' => (int)$userId,
            'username' => $user['username'],
            'avatar_url' => $avatarUrl,
            'content_html' => $contentHtml,
            'created_at' => date('Y-m-d H:i'),
            'parent_id' => $parentId,
            'reply_to_user_id' => $replyToUserId,
            'reply_to_username' => $replyToUsername,
            'reply_to_content' => $replyToContent,
        ];

        saveCommentIdempotencyResult($idempotencyKey, $responseComment, $commentTargetUrl);

        echo json_encode([
            'success' => true,
            'comment' => $responseComment,
            'redirect_url' => $commentTargetUrl,
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '提交失败，请稍后重试']);
    }
    exit;
}

// ========== 编辑评论 ==========
if ($action === 'update') {
    if (!isUserLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'code' => 'unauthorized', 'message' => '请先登录']);
        exit;
    }

    if (isUserBanned()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '您的账号已被封禁']);
        exit;
    }

    $commentId = intval($input['id'] ?? 0);
    $content = (string)($input['content'] ?? '');
    $content = str_replace(["\r\n", "\r"], "\n", $content);

    if ($commentId <= 0) {
        echo json_encode(['success' => false, 'message' => '无效的评论ID']);
        exit;
    }
    if (trim($content) === '') {
        echo json_encode(['success' => false, 'message' => '评论内容不能为空']);
        exit;
    }
    if (mb_strlen($content) > 5000) {
        echo json_encode(['success' => false, 'message' => '评论内容不能超过5000字']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, user_id, content, content_type, content_id FROM comments WHERE id = ? AND status = 'active'");
    $stmt->execute([$commentId]);
    $comment = $stmt->fetch();
    if (!$comment) {
        echo json_encode(['success' => false, 'message' => '评论不存在或已删除']);
        exit;
    }
    if ((int)$comment['user_id'] !== (int)getCurrentUserId()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限编辑此评论']);
        exit;
    }

    $sensitiveCheck = containsSensitiveWord($content, [
        'scene' => '评论回复',
        'page' => ($comment['content_type'] ?? 'comment') . ':' . (int)($comment['content_id'] ?? 0),
    ]);
    if ($sensitiveCheck['found']) {
        echo json_encode(['success' => false, 'message' => '评论包含违规内容，请修改后重新提交']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE comments SET content = ? WHERE id = ? AND user_id = ? AND status = 'active'");
    $result = $stmt->execute([$content, $commentId, getCurrentUserId()]);

    echo json_encode([
        'success' => $result ? true : false,
        'code' => $result ? 'ok' : 'db_error',
        'comment' => [
            'id' => $commentId,
            'content_html' => md_to_html($content),
            'content' => $content,
        ],
    ]);
    exit;
}

// ========== 删除评论 ==========
if ($action === 'delete') {
    if (!isUserLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'code' => 'unauthorized', 'message' => '请先登录']);
        exit;
    }

    $commentId = intval($input['id'] ?? 0);
    if ($commentId <= 0) {
        echo json_encode(['success' => false, 'message' => '无效的评论ID']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, user_id FROM comments WHERE id = ? AND status = 'active'");
    $stmt->execute([$commentId]);
    $comment = $stmt->fetch();
    if (!$comment) {
        echo json_encode(['success' => false, 'message' => '评论不存在或已删除']);
        exit;
    }

    $currentUserId = (int)getCurrentUserId();
    $isOwner = (int)($comment['user_id'] ?? 0) === $currentUserId;
    if (!$isOwner && !isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'code' => 'forbidden', 'message' => '无权限删除此评论']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE comments SET status = 'deleted' WHERE id = ? AND status = 'active'");
    $result = $stmt->execute([$commentId]);

    echo json_encode(['success' => $result ? true : false, 'code' => $result ? 'ok' : 'db_error']);
    exit;
}

echo json_encode(['success' => false, 'code' => 'unknown_action', 'message' => '未知操作']);
