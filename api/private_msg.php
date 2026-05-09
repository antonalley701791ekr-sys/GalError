<?php
require_once dirname(__DIR__) . '/includes/user_auth.php';
require_once dirname(__DIR__) . '/includes/sensitive_filter.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '不支持的请求方法']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['action'])) {
    echo json_encode(['success' => false, 'message' => '参数错误']);
    exit;
}

$pdo = getDB();
$action = $input['action'];

// ========== 发送私信 ==========
if ($action === 'send') {
    if (!isUserLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => '请先登录']);
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

    // 验证内容
    if ($content === '') {
        echo json_encode(['success' => false, 'message' => '消息内容不能为空']);
        exit;
    }
    if (mb_strlen($content, 'UTF-8') > 2000) {
        echo json_encode(['success' => false, 'message' => '消息内容不能超过2000字']);
        exit;
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
        echo json_encode(['success' => false, 'message' => '发送过于频繁，请等待 ' . $rateCheck['wait_seconds'] . ' 秒后再试']);
        exit;
    }

    // 插入私信
    $stmt = $pdo->prepare("INSERT INTO private_messages (sender_id, receiver_id, content) VALUES (?, ?, ?)");
    $result = $stmt->execute([$userId, $receiverId, $content]);

    if ($result) {
        $msgId = $pdo->lastInsertId();
        recordRateLimit($userId, 'private_msg');

        echo json_encode([
            'success' => true,
            'message' => [
                'id' => (int)$msgId,
                'sender_id' => $userId,
                'receiver_id' => $receiverId,
                'content' => $content,
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
        echo json_encode(['success' => false, 'message' => '请先登录']);
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
    $stmt = $pdo->prepare("SELECT id, sender_id, receiver_id, content, is_read, created_at
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
        $result[] = [
            'id' => (int)$msg['id'],
            'sender_id' => (int)$msg['sender_id'],
            'content' => $msg['content'],
            'created_at' => date('Y-m-d H:i', strtotime($msg['created_at'])),
            'is_mine' => ((int)$msg['sender_id'] === $userId)
        ];
    }

    echo json_encode([
        'success' => true,
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
        echo json_encode(['success' => false, 'message' => '请先登录']);
        exit;
    }

    $userId = getCurrentUserId();

    $sql = "SELECT 
                conv.partner_id,
                u.username AS partner_username,
                u.avatar AS partner_avatar,
                pm.content AS last_message,
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
        $result[] = [
            'partner_id' => (int)$conv['partner_id'],
            'partner_username' => $conv['partner_username'],
            'partner_avatar' => $avatarUrl,
            'last_message' => mb_substr($conv['last_message'], 0, 50, 'UTF-8'),
            'last_time' => date('Y-m-d H:i', strtotime($conv['last_time'])),
            'unread_count' => (int)$conv['unread_count']
        ];
    }

    echo json_encode(['success' => true, 'conversations' => $result]);
    exit;
}

echo json_encode(['success' => false, 'message' => '未知操作']);
