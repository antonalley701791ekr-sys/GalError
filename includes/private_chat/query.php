<?php
function loadPrivateChatQueryContext(PDO $pdo, int $userId, int $partnerId): array {
    $stmt = $pdo->prepare("SELECT id, username, avatar FROM users WHERE id = ? AND enabled = 1");
    $stmt->execute([$partnerId]);
    $partner = $stmt->fetch();
    if (!$partner) {
        return ['partner' => null, 'messages' => [], 'hasMore' => false, 'totalMessages' => 0];
    }

    $partnerAvatarUrl = '';
    if ($partner['avatar'] && file_exists(BASE_PATH . $partner['avatar'])) {
        $partnerAvatarUrl = '/' . $partner['avatar'];
    }

    $myAvatarUrl = getUserAvatarUrl();
    $myUsername = $_SESSION['user_username'] ?? '';
    $myInitial = mb_substr($myUsername, 0, 1);
    $partnerInitial = mb_substr($partner['username'], 0, 1);

    $stmt = $pdo->prepare("UPDATE private_messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
    $stmt->execute([$partnerId, $userId]);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM private_messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)");
    $stmt->execute([$userId, $partnerId, $partnerId, $userId]);
    $totalMessages = (int)$stmt->fetchColumn();

    $limit = 50;
    $stmt = $pdo->prepare("SELECT id, sender_id, content, created_at FROM private_messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) ORDER BY created_at DESC LIMIT " . intval($limit));
    $stmt->execute([$userId, $partnerId, $partnerId, $userId]);
    $messages = array_reverse($stmt->fetchAll());

    foreach ($messages as &$msg) {
        $msg['is_mine'] = ((int)$msg['sender_id'] === $userId);
        $content = (string)($msg['content'] ?? '');

        // 私信列表优先保证稳定性，超长内容降级为安全纯文本，避免 markdown 解析/链接探测消耗过多内存。
        if (mb_strlen($content, 'UTF-8') > 4000) {
            $msg['content_html'] = nl2br(htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        } else {
            $msg['content_html'] = md_to_html($content);
        }

        $msg['created_at_display'] = date('m-d H:i', strtotime($msg['created_at']));
    }
    unset($msg);

    return compact('partner', 'partnerAvatarUrl', 'myAvatarUrl', 'myInitial', 'partnerInitial', 'messages', 'totalMessages') + ['hasMore' => $totalMessages > $limit];
}
