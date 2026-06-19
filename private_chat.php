<?php
require_once 'includes/user_auth.php';
require_once 'includes/markdown.php';
require_once 'includes/view.php';
requireUserLogin();

$pdo = getDB();
$userId = getCurrentUserId();
$partnerId = intval($_GET['user_id'] ?? 0);

if ($partnerId <= 0 || $partnerId === $userId) {
    header('Location: /private_messages');
    exit;
}

// 查询对方用户信息
$stmt = $pdo->prepare("SELECT id, username, avatar FROM users WHERE id = ? AND enabled = 1");
$stmt->execute([$partnerId]);
$partner = $stmt->fetch();

if (!$partner) {
    header('Location: /private_messages');
    exit;
}

$partnerAvatarUrl = '';
if ($partner['avatar'] && file_exists(BASE_PATH . $partner['avatar'])) {
    $partnerAvatarUrl = '/' . $partner['avatar'];
}

// 当前用户头像
$myAvatarUrl = getUserAvatarUrl();
$myUsername = $_SESSION['user_username'] ?? '';

// 将对方发来的未读消息标为已读
$stmt = $pdo->prepare("UPDATE private_messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
$stmt->execute([$partnerId, $userId]);

// 获取消息总数
$stmt = $pdo->prepare("SELECT COUNT(*) FROM private_messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)");
$stmt->execute([$userId, $partnerId, $partnerId, $userId]);
$totalMessages = (int)$stmt->fetchColumn();

// 查询最近 50 条消息（按时间倒序取，显示时正序）
$limit = 50;
$stmt = $pdo->prepare("SELECT id, sender_id, content, created_at
    FROM private_messages
    WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
    ORDER BY created_at DESC
    LIMIT " . intval($limit));
$stmt->execute([$userId, $partnerId, $partnerId, $userId]);
$messages = array_reverse($stmt->fetchAll());

$hasMore = $totalMessages > $limit;

// 私信页强制不缓存，避免移动端拿到旧脚本
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');
ob_start();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>与 <?php echo h($partner['username']); ?> 的对话 - <?php echo h(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/style.css'); ?>">
    <link rel="stylesheet" href="/assets/css/user.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/user.css'); ?>">
    <link rel="stylesheet" href="/assets/css/markdown-editor.css?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/css/markdown-editor.css'); ?>">
    <style>
        html, body.private-chat-page {
            height: 100%;
            overflow: hidden;
        }
        body.private-chat-page .main {
            height: calc(100vh - var(--nav-height));
            height: calc(100dvh - var(--nav-height));
            overflow: hidden;
        }
        body.private-chat-page .main .container {
            height: 100%;
            display: flex;
            flex-direction: column;
            padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 10px);
        }
        .pm-chat-header {
            flex: 0 0 auto;
        }
        #loadMoreArea {
            flex: 0 0 auto;
        }
        .pm-chat-container {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            transition: padding-bottom .2s ease, height .2s ease;
            padding-bottom: calc(var(--pm-keyboard-offset, 0px) * 0.22);
            scroll-behavior: smooth;
        }
        .pm-send-form {
            flex: 0 0 auto;
            padding-bottom: env(safe-area-inset-bottom, 0px);
            padding-right: 0;
            transform: translateY(calc(-1 * var(--pm-keyboard-offset, 0px)));
            transition: transform .18s ease, box-shadow .18s ease, background-color .18s ease;
            will-change: transform;
        }
        .pm-send-form.keyboard-open {
            background: var(--bg-secondary);
            box-shadow: 0 -6px 18px rgba(0,0,0,0.12);
        }
        .pm-send-form {
            display: flex;
            align-items: flex-start;
            gap: 16px;
        }
        .pm-send-form .pm-send-main {
            flex: 1 1 auto;
            min-width: 0;
        }
        .pm-send-form .pm-toolbar {
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 5;
            min-height: 34px;
            margin-bottom: 8px;
            width: 100%;
            box-sizing: border-box;
        }
        .pm-send-form .pm-toolbar-btn {
            appearance: none;
            border: 1px solid var(--glass-border);
            background: rgba(255,255,255,0.72);
            color: var(--text-primary);
            border-radius: 12px;
            width: 44px;
            height: 44px;
            padding: 0;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 24px rgba(0,0,0,0.06);
            transition: transform .15s ease, border-color .15s ease, background-color .15s ease, box-shadow .15s ease;
        }
        .pm-send-form .pm-toolbar-btn:hover {
            transform: translateY(-1px);
            border-color: var(--accent-purple);
            box-shadow: 0 10px 28px rgba(167,139,250,0.16);
        }
        .pm-send-form .pm-toolbar-btn:disabled {
            opacity: .55;
            cursor: not-allowed;
            transform: none;
        }
        .pm-send-form .pm-toolbar-btn .pm-icon {
            width: 24px;
            height: 24px;
            display: block;
            fill: currentColor;
        }
        .pm-send-form .pm-preview-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 12px;
            padding-top: 6px;
        }
        .pm-send-form .pm-preview-item {
            position: relative;
            width: 96px;
            height: 96px;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--glass-border);
            background: var(--bg-secondary);
        }
        .pm-send-form .pm-preview-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .pm-send-form .pm-preview-remove {
            position: absolute;
            top: 6px;
            right: 6px;
            width: 24px;
            height: 24px;
            border: 0;
            border-radius: 999px;
            background: rgba(0,0,0,.65);
            color: #fff;
            cursor: pointer;
        }
        .pm-send-form #msgInput {
            display: block;
            width: 100%;
            height: auto;
            min-height: calc(1.75em * 3 + 28px);
            max-height: calc(1.75em * 3 + 28px);
            padding: 14px 16px;
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            background: var(--glass-bg);
            color: var(--text-primary);
            line-height: 1.75;
            resize: none;
            overflow-y: auto;
            outline: none;
            box-sizing: border-box;
        }
        .pm-send-form .pm-send-btn {
            width: 68px;
            min-width: 68px;
            align-self: flex-start;
            margin: 42px 0 0 0;
            z-index: 4;
            padding: 8px 0;
            line-height: 1.1;
            flex: 0 0 68px;
        }
        @media (max-width: 768px) {
            body.private-chat-page .main .container {
                padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 14px);
            }
            .pm-send-form {
                gap: 12px;
            }
            .pm-send-form .pm-send-btn {
                margin-top: 42px;
            }
            .pm-send-form .pm-toolbar-btn {
                width: 40px;
                height: 40px;
            }
            .pm-send-form .pm-toolbar-btn .pm-icon {
                width: 22px;
                height: 22px;
            }
        }
        .pm-send-form #msgInput:focus {
            border-color: var(--accent-purple);
            box-shadow: 0 0 0 2px rgba(167,139,250,0.15);
        }
        .pm-bubble-content.markdown-body > :first-child {
            margin-top: 0;
        }
        .pm-bubble-content.markdown-body > :last-child {
            margin-bottom: 0;
        }
        .pm-bubble-content.markdown-body p,
        .pm-bubble-content.markdown-body ul,
        .pm-bubble-content.markdown-body ol,
        .pm-bubble-content.markdown-body pre,
        .pm-bubble-content.markdown-body blockquote {
            margin-bottom: 0.5em;
        }
    </style>
    <?php renderSiteHead(); ?>
</head>
<body class="has-fixed-nav private-chat-page"
      data-partner-id="<?php echo $partnerId; ?>"
      data-my-avatar="<?php echo h($myAvatarUrl); ?>"
      data-my-initial="<?php echo h(mb_substr($myUsername, 0, 1)); ?>"
      data-partner-avatar="<?php echo h($partnerAvatarUrl); ?>"
      data-partner-initial="<?php echo h(mb_substr($partner['username'], 0, 1)); ?>">
    <?php include 'includes/header.php'; ?>

    <main class="main">
        <div class="container" style="max-width:800px;">
            <div class="pm-chat-header">
                <a href="/private_messages">&larr; 返回</a>
                <h2>与 <?php echo h($partner['username']); ?> 的对话</h2>
            </div>

            <?php if ($hasMore): ?>
                <div class="pm-load-more" id="loadMoreArea">
                    <a href="#" id="loadMoreBtn" data-page="2">加载更早的消息</a>
                </div>
            <?php endif; ?>

            <div class="pm-chat-container" id="chatContainer">
                <?php foreach ($messages as $msg): ?>
                    <?php
                        $isMine = ((int)$msg['sender_id'] === $userId);
                        $rawContent = (string)($msg['content'] ?? '');
                        $displayText = $rawContent;
                        $displayImages = [];
                        $decodedContent = json_decode($rawContent, true);
                        if (is_array($decodedContent)) {
                            $displayText = (string)($decodedContent['text'] ?? '');
                            if (!empty($decodedContent['images']) && is_array($decodedContent['images'])) {
                                foreach ($decodedContent['images'] as $img) {
                                    if (is_string($img) && trim($img) !== '') {
                                        $displayImages[] = $img;
                                    }
                                }
                            }
                        }
                    ?>
                    <div class="pm-chat-bubble <?php echo $isMine ? 'mine' : 'theirs'; ?>">
                        <?php if ($isMine): ?>
                            <?php if ($myAvatarUrl): ?>
                                <img src="<?php echo h($myAvatarUrl); ?>" class="pm-bubble-avatar" alt="">
                            <?php else: ?>
                                <div class="pm-bubble-avatar fallback"><?php echo h(mb_substr($myUsername, 0, 1)); ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if ($partnerAvatarUrl): ?>
                                <img src="<?php echo h($partnerAvatarUrl); ?>" class="pm-bubble-avatar" alt="">
                            <?php else: ?>
                                <div class="pm-bubble-avatar fallback"><?php echo h(mb_substr($partner['username'], 0, 1)); ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="pm-bubble-body">
                            <div class="pm-bubble-content markdown-body">
                                <?php if ($displayText !== ''): ?>
                                    <?php echo md_to_html($displayText); ?>
                                <?php endif; ?>
                                <?php if (!empty($displayImages)): ?>
                                    <div class="pm-inline-images">
                                        <?php foreach ($displayImages as $img): ?>
                                            <img src="<?php echo h($img); ?>" alt="私信图片" class="pm-inline-image js-image-viewer-trigger" data-viewer-src="<?php echo h($img); ?>" data-viewer-alt="私信图片">
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <span class="pm-bubble-time"><?php echo date('m-d H:i', strtotime($msg['created_at'])); ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (empty($messages)): ?>
                    <div style="text-align:center;padding:40px 0;color:var(--text-muted);font-size:0.88rem;">
                        暂无消息，发送第一条私信吧
                    </div>
                <?php endif; ?>
            </div>

            <div class="pm-error-msg" id="errorMsg"></div>

            <div class="pm-send-form" id="pmSendForm">
                <div class="pm-send-main">
                    <div class="pm-toolbar">
                        <button class="pm-toolbar-btn" id="imageUploadBtn" type="button" aria-label="上传图片" title="上传图片">
                            <svg class="pm-icon" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2Zm0-12h14v8.2l-3.1-3.1a1 1 0 0 0-1.4 0l-2.4 2.4-1.8-1.8a1 1 0 0 0-1.4 0L5 17.2V7Zm3 2a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Z"></path>
                            </svg>
                        </button>
                        <input id="imageUploadInput" type="file" accept="image/*" multiple hidden>
                    </div>
                    <div class="pm-preview-list" id="imagePreviewList"></div>
                    <textarea id="msgInput" placeholder="输入消息..." maxlength="2000" autocomplete="off"></textarea>
                </div>
                <button class="pm-send-btn" id="sendBtn" type="button">发送</button>
            </div>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>

    <script src="/assets/js/marked.min.js?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/js/marked.min.js'); ?>"></script>
    <script>
    (function() {
        if (typeof marked === 'undefined') return;
        var renderer = new marked.Renderer();
        renderer.link = function(token) {
            var href = token.href || '';
            var title = token.title || '';
            var text = token.text || href;
            var titleAttr = title ? ' title="' + text + '"' : '';
            return '<a href="' + href + '"' + titleAttr + ' target="_blank" rel="noopener noreferrer">' + text + '</a>';
        };
        marked.setOptions({ breaks: true, renderer: renderer });
    })();
    </script>

    <script src="/assets/js/private-chat.js?v=<?php echo ASSETS_VER . '-' . @filemtime(BASE_PATH . '/assets/js/private-chat.js'); ?>"></script>
</body>
</html>
<?php
$pageHtml = ob_get_clean();
view('front/private_chat.twig', ['page_html' => $pageHtml]);

