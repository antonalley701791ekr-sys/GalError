<?php
require_once dirname(__DIR__) . '/includes/user_auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/api_response.php';
require_once dirname(__DIR__) . '/includes/api_security.php';
require_once dirname(__DIR__) . '/includes/sensitive_filter.php';
require_once dirname(__DIR__) . '/includes/image_utils.php';

header('Content-Type: application/json');

function ensurePrivateMessageSchema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $columns = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM private_messages");
        if ($stmt) {
            foreach ($stmt->fetchAll() as $row) {
                $columns[strtolower($row['Field'])] = true;
            }
        }
    } catch (Throwable $e) {
        return;
    }

    $alter = [];
    if (empty($columns['content_text'])) {
        $alter[] = "ADD COLUMN content_text TEXT NULL AFTER content";
    }
    if (empty($columns['content_images'])) {
        $alter[] = "ADD COLUMN content_images JSON NULL AFTER content_text";
    }
    if (empty($alter)) return;

    try {
        $pdo->exec("ALTER TABLE private_messages " . implode(', ', $alter));
    } catch (Throwable $e) {
        // 兼容旧数据库：如果 JSON 类型不可用则降级为 TEXT
        try {
            $fallback = [];
            if (empty($columns['content_text'])) {
                $fallback[] = "ADD COLUMN content_text TEXT NULL AFTER content";
            }
            if (empty($columns['content_images'])) {
                $fallback[] = "ADD COLUMN content_images TEXT NULL AFTER content_text";
            }
            if ($fallback) {
                $pdo->exec("ALTER TABLE private_messages " . implode(', ', $fallback));
            }
        } catch (Throwable $e2) {
            return;
        }
    }
}

function decodePrivateMessagePayload(array $row): array {
    $text = (string)($row['content_text'] ?? '');
    $imagesRaw = $row['content_images'] ?? null;
    $images = [];
    if (is_string($imagesRaw) && $imagesRaw !== '') {
        $decoded = json_decode($imagesRaw, true);
        if (is_array($decoded)) $images = array_values(array_filter($decoded, 'is_string'));
    } elseif (is_array($imagesRaw)) {
        $images = array_values(array_filter($imagesRaw, 'is_string'));
    }
    if ($text === '' && !empty($row['content'])) {
        $legacy = json_decode((string)$row['content'], true);
        if (is_array($legacy)) {
            $text = (string)($legacy['text'] ?? '');
            if (empty($images) && !empty($legacy['images']) && is_array($legacy['images'])) {
                $images = array_values(array_filter($legacy['images'], 'is_string'));
            }
        } else {
            $text = (string)$row['content'];
        }
    }
    return [$text, $images];
}

function normalizePrivateMessageImageUrl(string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    if (preg_match('/^https?:\/\//i', $path)) return $path;

    // 兼容旧数据：历史记录里曾保存过 /chat/data/private_messages/... 这样的旧路径
    $path = preg_replace('#^/?chat/data/private_messages/#i', 'data/private_messages/', $path);
    $path = preg_replace('#^/?chat/data/#i', 'data/', $path);

    return '/' . ltrim($path, '/');
}

function buildPrivateMessagePayload(string $text, array $images): array {
    $normalizedImages = [];
    foreach ($images as $img) {
        if (!is_string($img) || trim($img) === '') continue;
        $normalizedImages[] = normalizePrivateMessageImageUrl($img);
    }
    return [
        'text' => $text,
        'images' => array_values($normalizedImages),
    ];
}


api_require_basic_auth('private_msg');
api_enforce_rate_limit('private_msg', 70, 60);

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

if (!csrf_validate_request_flexible(['default'])) {
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
ensurePrivateMessageSchema($pdo);
$action = $input['action'];

// ========== 发送私信 ==========
if ($action === 'send') {
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

    $userId = getCurrentUserId();
    $receiverId = intval($input['receiver_id'] ?? 0);
    $content = trim($input['content'] ?? '');
    $images = $input['images'] ?? [];
    if (!is_array($images)) {
        $images = [];
    }

    // 验证接收者
    if ($receiverId <= 0) {
        echo json_encode(['success' => false, 'message' => '无效的接收者']);
        exit;
    }
    if ($receiverId === $userId) {
        echo json_encode(['success' => false, 'message' => '不能给自己发送私信']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, username, banned FROM users WHERE id = ? AND enabled = 1");
    $stmt->execute([$receiverId]);
    $receiver = $stmt->fetch();
    if (!$receiver) {
        echo json_encode(['success' => false, 'message' => '用户不存在']);
        exit;
    }

    if ($content === '' && empty($images)) {
        echo json_encode(['success' => false, 'message' => '消息内容不能为空']);
        exit;
    }
    if (mb_strlen($content, 'UTF-8') > 2000) {
        echo json_encode(['success' => false, 'message' => '消息内容不能超过2000字']);
        exit;
    }

    $storedImages = [];
    foreach ($images as $imageData) {
        if (!is_string($imageData) || trim($imageData) === '') {
            continue;
        }
        $saved = saveBase64ImageToTarget($imageData, 'private_messages');
        if (!$saved['success']) {
            echo json_encode(['success' => false, 'message' => '图片上传失败：' . $saved['message']]);
            exit;
        }
        $storedImages[] = $saved['path'];
    }

    // 敏感词检查
    $sensitiveCheck = containsSensitiveWord($content, [
        'scene' => '私信聊天',
        'page' => '/private_chat?user_id=' . $receiverId,
    ]);
    if ($sensitiveCheck['found']) {
        echo json_encode(['success' => false, 'message' => '消息包含违规内容，请修改后重新发送']);
        exit;
    }

    // 频率限制
    $rateCheck = checkRateLimit($userId, 'private_msg', 10);
    if (!$rateCheck['allowed']) {
        echo json_encode(['success' => false, 'code' => 'rate_limited', 'message' => '发送过于频繁，请等待 ' . $rateCheck['wait_seconds'] . ' 秒后再试']);
        exit;
    }

    $payload = buildPrivateMessagePayload($content, $storedImages);
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("INSERT INTO private_messages (sender_id, receiver_id, content, content_text, content_images) VALUES (?, ?, ?, ?, ?)");
    $result = $stmt->execute([
        $userId,
        $receiverId,
        $payloadJson,
        $content,
        empty($storedImages) ? null : json_encode($storedImages, JSON_UNESCAPED_UNICODE)
    ]);

    if ($result) {
        $msgId = $pdo->lastInsertId();
        recordRateLimit($userId, 'private_msg');

        echo json_encode([
            'success' => true,
            'code' => 'ok',
            'message' => [
                'id' => (int)$msgId,
                'sender_id' => $userId,
                'receiver_id' => $receiverId,
                'content' => $payloadJson,
                'content_text' => $content,
                'content_images' => $storedImages,
                'created_at' => date('Y-m-d H:i'),
                'is_mine' => true
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => '发送失败，请稍后重试']);
    }
    exit;
}

// ========== 获取聊天记录 ==========
if ($action === 'history') {
    if (!isUserLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'code' => 'unauthorized', 'message' => '请先登录']);
        exit;
    }

    $userId = getCurrentUserId();
    $partnerId = intval($input['partner_id'] ?? 0);
    $page = max(1, intval($input['page'] ?? 1));
    $perPage = 30;
    $offset = ($page - 1) * $perPage;

    if ($partnerId <= 0) {
        echo json_encode(['success' => false, 'message' => '无效的用户ID']);
        exit;
    }

    // 将对方发来的未读消息标为已读
    $stmt = $pdo->prepare("UPDATE private_messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
    $stmt->execute([$partnerId, $userId]);

    // 查询聊天记录
    $stmt = $pdo->prepare("SELECT id, sender_id, receiver_id, content, content_text, content_images, is_read, created_at
        FROM private_messages
        WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
        ORDER BY created_at DESC
        LIMIT " . intval($offset) . ", " . intval($perPage));
    $stmt->execute([$userId, $partnerId, $partnerId, $userId]);
    $messages = $stmt->fetchAll();

    // 获取总数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM private_messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)");
    $stmt->execute([$userId, $partnerId, $partnerId, $userId]);
    $total = (int)$stmt->fetchColumn();

    $result = [];
    foreach ($messages as $msg) {
        [$text, $images] = decodePrivateMessagePayload($msg);
        $result[] = [
            'id' => (int)$msg['id'],
            'sender_id' => (int)$msg['sender_id'],
            'content' => buildPrivateMessagePayload($text, $images),
            'content_text' => $text,
            'content_images' => $images,
            'created_at' => date('Y-m-d H:i', strtotime($msg['created_at'])),
            'is_mine' => ((int)$msg['sender_id'] === $userId)
        ];
    }

    echo json_encode([
        'success' => true,
        'code' => 'ok',
        'messages' => $result,
        'total' => $total,
        'has_more' => ($offset + $perPage) < $total
    ]);
    exit;
}

// ========== 获取会话列表 ==========
if ($action === 'conversations') {
    if (!isUserLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'code' => 'unauthorized', 'message' => '请先登录']);
        exit;
    }

    $userId = getCurrentUserId();

    $sql = "SELECT 
                conv.partner_id,
                u.username AS partner_username,
                u.avatar AS partner_avatar,
                pm.content AS last_message,
                pm.content_text AS last_message_text,
                pm.content_images AS last_message_images,
                pm.created_at AS last_time,
                COALESCE(unread.cnt, 0) AS unread_count
            FROM (
                SELECT 
                    CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS partner_id,
                    MAX(id) AS last_msg_id
                FROM private_messages
                WHERE sender_id = ? OR receiver_id = ?
                GROUP BY partner_id
            ) AS conv
            JOIN private_messages pm ON pm.id = conv.last_msg_id
            JOIN users u ON u.id = conv.partner_id
            LEFT JOIN (
                SELECT sender_id, COUNT(*) AS cnt
                FROM private_messages
                WHERE receiver_id = ? AND is_read = 0
                GROUP BY sender_id
            ) AS unread ON unread.sender_id = conv.partner_id
            ORDER BY pm.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $userId, $userId, $userId]);
    $conversations = $stmt->fetchAll();

    $result = [];
    foreach ($conversations as $conv) {
        $avatarUrl = '';
        if ($conv['partner_avatar'] && file_exists(BASE_PATH . $conv['partner_avatar'])) {
            $avatarUrl = '/' . $conv['partner_avatar'];
        }
        [$lastText, $lastImages] = decodePrivateMessagePayload($conv);
        $result[] = [
            'partner_id' => (int)$conv['partner_id'],
            'partner_username' => $conv['partner_username'],
            'partner_avatar' => $avatarUrl,
            'last_message' => mb_substr($lastText, 0, 50, 'UTF-8'),
            'last_message_images' => $lastImages,
            'last_time' => date('Y-m-d H:i', strtotime($conv['last_time'])),
            'unread_count' => (int)$conv['unread_count']
        ];
    }

    echo json_encode(['success' => true, 'code' => 'ok', 'conversations' => $result]);
    exit;
}

echo json_encode(['success' => false, 'code' => 'unknown_action', 'message' => '未知操作']);
