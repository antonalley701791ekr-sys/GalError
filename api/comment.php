<?php
require_once dirname(__DIR__) . '/includes/user_auth.php';
require_once dirname(__DIR__) . '/includes/markdown.php';
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

// ========== 创建评论 ==========
if ($action === 'create') {
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

    $contentType = $input['content_type'] ?? '';
    $contentId = intval($input['content_id'] ?? 0);
    $content = (string)($input['content'] ?? '');
    $parentId = isset($input['parent_id']) ? intval($input['parent_id']) : null;
    $parentComment = null;

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

    $userId = getCurrentUserId();
    $rateCheck = checkRateLimit($userId, 'comment');
    if (!$rateCheck['allowed']) {
        echo json_encode(['success' => false, 'message' => '评论过于频繁，请等待 ' . $rateCheck['wait_seconds'] . ' 秒后再试']);
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
        echo json_encode(['success' => false, 'message' => '请先登录']);
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
    if (!isAdmin()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '无权限']);
        exit;
    }

    $commentId = intval($input['id'] ?? 0);
    if ($commentId <= 0) {
        echo json_encode(['success' => false, 'message' => '无效的评论ID']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE comments SET status = 'deleted' WHERE id = ?");
    $result = $stmt->execute([$commentId]);

    echo json_encode(['success' => $result ? true : false]);
    exit;
}

echo json_encode(['success' => false, 'message' => '未知操作']);
