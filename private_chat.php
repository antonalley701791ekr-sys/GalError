<?php
require_once 'includes/user_auth.php';
require_once 'includes/markdown.php';
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
        .pm-send-form .pm-input-wrap {
            position: relative;
            display: flex;
            align-items: flex-start;
            width: calc(100% + 84px);
            max-width: none;
            overflow: visible;
        }
        .pm-send-form #msgInput {
            display: block;
            width: calc(100% - 84px);
            flex: 0 0 calc(100% - 84px);
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
        .pm-send-form .pm-input-wrap > .pm-send-btn {
            position: static !important;
            width: 68px;
            min-width: 68px;
            margin: 0 0 0 16px !important;
            z-index: 4;
            padding: 8px 0;
            line-height: 1.1;
            align-self: flex-start !important;
            transform: none !important;
            flex: 0 0 68px;
        }
        @media (min-width: 769px) {
            .pm-send-form {
                padding-right: 0 !important;
            }
            .pm-send-form .pm-input-wrap {
                width: calc(100% + 84px);
            }
            .pm-send-form .pm-input-wrap > #msgInput {
                width: calc(100% - 84px);
                flex: 0 0 calc(100% - 84px);
            }
            .pm-send-form .pm-input-wrap > .pm-send-btn {
                margin-left: 16px !important;
                right: auto !important;
            }
        }
        @media (max-width: 768px) {
            body.private-chat-page .main .container {
                padding-bottom: calc(env(safe-area-inset-bottom, 0px) + 14px);
            }
            .pm-send-form {
                position: relative;
                z-index: 2;
                margin-bottom: 0;
                padding-top: 24px;
                padding-right: 84px;
                width: 100%;
                box-sizing: border-box;
            }
            .pm-send-form .pm-input-wrap {
                position: relative;
                display: block;
                width: 100%;
                overflow: visible;
            }
            .pm-send-form .pm-input-wrap > #msgInput {
                width: 100%;
                flex: initial;
                min-width: 0;
            }
            .pm-send-form .pm-input-wrap > .pm-send-btn {
                position: absolute !important;
                top: 0 !important;
                right: -68px !important;
                left: auto !important;
                bottom: auto !important;
                margin: 0 !important;
                transform: none !important;
                z-index: 4;
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
<body class="has-fixed-nav private-chat-page">
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
                    <?php $isMine = ((int)$msg['sender_id'] === $userId); ?>
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
                            <div class="pm-bubble-content markdown-body"><?php echo md_to_html($msg['content']); ?></div>
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
                <div class="pm-input-wrap">
                    <textarea id="msgInput" placeholder="输入消息..." maxlength="2000" autocomplete="off"></textarea>
                    <button class="pm-send-btn" id="sendBtn" type="button">发送</button>
                </div>
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

    <script>
    (function() {
        var container = document.getElementById('chatContainer');
        var input = document.getElementById('msgInput');
        var sendBtn = document.getElementById('sendBtn');
        var sendForm = document.getElementById('pmSendForm');
        var errorMsg = document.getElementById('errorMsg');
        var partnerId = <?php echo $partnerId; ?>;
        var myAvatarUrl = <?php echo json_encode($myAvatarUrl); ?>;
        var myInitial = <?php echo json_encode(mb_substr($myUsername, 0, 1)); ?>;

        var baseInnerHeight = window.innerHeight;
        var baseVisualHeight = window.visualViewport ? window.visualViewport.height : window.innerHeight;
        var keyboardOffsetPx = 0;
        var isMobileViewport = window.matchMedia('(max-width: 768px)').matches;
        var keyboardPollTimer = null;

        function refreshMobileViewportFlag() {
            isMobileViewport = window.matchMedia('(max-width: 768px)').matches;
            if (!isMobileViewport) {
                stopKeyboardPolling();
                setKeyboardOffset(0);
            }
        }

        function isInputFocused() {
            return document.activeElement === input;
        }

        function startKeyboardPolling() {
            if (keyboardPollTimer || !isMobileViewport) return;
            keyboardPollTimer = setInterval(syncKeyboardOffset, 100);
        }

        function stopKeyboardPolling() {
            if (!keyboardPollTimer) return;
            clearInterval(keyboardPollTimer);
            keyboardPollTimer = null;
        }

        function setKeyboardOffset(px) {
            if (!isMobileViewport) {
                keyboardOffsetPx = 0;
                document.documentElement.style.setProperty('--pm-keyboard-offset', '0px');
                if (sendForm) {
                    sendForm.classList.remove('keyboard-open');
                    sendForm.style.transform = 'translateY(0px)';
                }
                if (container) {
                    container.style.paddingBottom = '';
                }
                return;
            }

            keyboardOffsetPx = Math.max(0, Math.round(px || 0));
            document.documentElement.style.setProperty('--pm-keyboard-offset', keyboardOffsetPx + 'px');

            // 强制行内样式，避免机型/浏览器对 CSS 变量计算不一致
            if (sendForm) {
                sendForm.style.transform = keyboardOffsetPx > 0
                    ? ('translateY(' + (-keyboardOffsetPx) + 'px)')
                    : 'translateY(0px)';
                sendForm.classList.toggle('keyboard-open', keyboardOffsetPx > 0);
            }

            if (container) {
                container.style.paddingBottom = keyboardOffsetPx > 0
                    ? (Math.round(keyboardOffsetPx * 0.22) + 'px')
                    : '';
            }

            if (keyboardOffsetPx > 0) {
                scrollToBottom();
            }
        }

        function syncKeyboardOffset() {
            if (!isMobileViewport) {
                setKeyboardOffset(0);
                return;
            }

            var focused = isInputFocused();
            var vv = window.visualViewport;
            var inferred = 0;

            if (!focused) {
                setKeyboardOffset(0);
                return;
            }

            if (vv) {
                var layoutHeight = window.innerHeight || baseInnerHeight;
                var occluded = layoutHeight - (vv.height + vv.offsetTop);
                occluded = Math.max(0, occluded);

                // 仅使用真实遮挡值，避免兜底误判把输入框顶到顶部
                inferred = occluded;
            } else {
                inferred = 0;
            }

            // 保守上限，避免异常值
            var maxOffset = Math.min(260, Math.round((window.innerHeight || 800) * 0.36));
            inferred = Math.min(Math.max(0, inferred), maxOffset);

            if (inferred < 12) {
                inferred = 0;
            }

            setKeyboardOffset(inferred);
        }

        // 滚动到底部
        function scrollToBottom() {
            container.scrollTop = container.scrollHeight;
        }
        scrollToBottom();

        // 隐藏错误
        function hideError() {
            errorMsg.classList.remove('show');
            errorMsg.textContent = '';
        }

        // 显示错误
        function showError(msg) {
            errorMsg.textContent = msg;
            errorMsg.classList.add('show');
            setTimeout(hideError, 5000);
        }

        // 创建气泡 HTML
        function createBubbleHTML(content, time) {
            var avatarHtml = '';
            if (myAvatarUrl) {
                avatarHtml = '<img src="' + escapeHtml(myAvatarUrl) + '" class="pm-bubble-avatar" alt="">';
            } else {
                avatarHtml = '<div class="pm-bubble-avatar fallback">' + escapeHtml(myInitial) + '</div>';
            }
            return '<div class="pm-chat-bubble mine">' +
                avatarHtml +
                '<div class="pm-bubble-body">' +
                '<div class="pm-bubble-content markdown-body">' + renderMarkdown(content) + '</div>' +
                '<span class="pm-bubble-time">' + escapeHtml(time) + '</span>' +
                '</div></div>';
        }

        function renderMarkdown(text) {
            var content = String(text || '');
            if (typeof marked !== 'undefined') {
                var html = marked.parse(content, { breaks: true });
                // 与服务端渲染风格对齐：单段落去掉外层 <p>，避免新消息即时渲染出现额外底部留白
                var singleParagraph = html.match(/^\s*<p>([\s\S]*)<\/p>\s*$/i);
                if (singleParagraph) {
                    return singleParagraph[1];
                }
                return html;
            }
            return escapeHtml(content).replace(/\n/g, '<br>');
        }

        function escapeHtml(text) {
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        }

        // 发送消息
        function sendMessage() {
            var content = input.value.trim();
            if (!content) return;

            hideError();
            sendBtn.disabled = true;

            fetch('/api/private_msg.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'send',
                    receiver_id: partnerId,
                    content: content
                })
            })
            .then(function(res) {
                if (!res.ok) {
                    throw new Error('HTTP ' + res.status);
                }
                return res.json();
            })
            .then(function(data) {
                sendBtn.disabled = false;
                if (data.success) {
                    var emptyTip = container.querySelector('[style*="text-align:center"]');
                    if (emptyTip) emptyTip.remove();

                    container.insertAdjacentHTML('beforeend', createBubbleHTML(content, data.message.created_at));
                    input.value = '';
                    if (typeof renderPmPreview === 'function') {
                        renderPmPreview();
                    }
                    scrollToBottom();
                } else {
                    showError(data.message || '发送失败');
                }
            })
            .catch(function(err) {
                sendBtn.disabled = false;
                showError('网络错误，请稍后重试（' + (err && err.message ? err.message : '未知错误') + '）');
            });
        }

        sendBtn.addEventListener('click', sendMessage);
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                sendMessage();
                return;
            }
            if (e.key === 'Tab') {
                e.preventDefault();
                var pos = input.selectionStart;
                input.value = input.value.substring(0, pos) + '    ' + input.value.substring(input.selectionEnd);
                input.selectionStart = input.selectionEnd = pos + 4;
            }
        });

        // 加载更多消息
        var loadMoreBtn = document.getElementById('loadMoreBtn');
        if (loadMoreBtn) {
            loadMoreBtn.addEventListener('click', function(e) {
                e.preventDefault();
                var page = parseInt(this.getAttribute('data-page'));
                var btn = this;
                btn.textContent = '加载中...';

                fetch('/api/private_msg.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        action: 'history',
                        partner_id: partnerId,
                        page: page
                    })
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.success && data.messages.length > 0) {
                        var partnerAvatarUrl = <?php echo json_encode($partnerAvatarUrl); ?>;
                        var partnerInitial = <?php echo json_encode(mb_substr($partner['username'], 0, 1)); ?>;

                        // 消息是倒序的，需要反转
                        var msgs = data.messages.reverse();
                        var html = '';
                        for (var i = 0; i < msgs.length; i++) {
                            var msg = msgs[i];
                            var avatarHtml = '';
                            if (msg.is_mine) {
                                if (myAvatarUrl) {
                                    avatarHtml = '<img src="' + escapeHtml(myAvatarUrl) + '" class="pm-bubble-avatar" alt="">';
                                } else {
                                    avatarHtml = '<div class="pm-bubble-avatar fallback">' + escapeHtml(myInitial) + '</div>';
                                }
                            } else {
                                if (partnerAvatarUrl) {
                                    avatarHtml = '<img src="' + escapeHtml(partnerAvatarUrl) + '" class="pm-bubble-avatar" alt="">';
                                } else {
                                    avatarHtml = '<div class="pm-bubble-avatar fallback">' + escapeHtml(partnerInitial) + '</div>';
                                }
                            }
                            html += '<div class="pm-chat-bubble ' + (msg.is_mine ? 'mine' : 'theirs') + '">' +
                                avatarHtml +
                                '<div class="pm-bubble-body">' +
                                '<div class="pm-bubble-content markdown-body">' + renderMarkdown(msg.content) + '</div>' +
                                '<span class="pm-bubble-time">' + escapeHtml(msg.created_at) + '</span>' +
                                '</div></div>';
                        }

                        var oldHeight = container.scrollHeight;
                        container.insertAdjacentHTML('afterbegin', html);
                        container.scrollTop = container.scrollHeight - oldHeight;

                        if (data.has_more) {
                            btn.setAttribute('data-page', page + 1);
                            btn.textContent = '加载更早的消息';
                        } else {
                            document.getElementById('loadMoreArea').style.display = 'none';
                        }
                    } else {
                        document.getElementById('loadMoreArea').style.display = 'none';
                    }
                })
                .catch(function() {
                    btn.textContent = '加载失败，点击重试';
                });
            });
        }

        // 输入框焦点
        input.focus();

        window.addEventListener('resize', function() {
            refreshMobileViewportFlag();
            baseInnerHeight = Math.max(baseInnerHeight, window.innerHeight);
            if (window.visualViewport) {
                baseVisualHeight = Math.max(baseVisualHeight, window.visualViewport.height);
            }
            syncKeyboardOffset();
        });

        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', syncKeyboardOffset);
            window.visualViewport.addEventListener('scroll', syncKeyboardOffset);
            window.addEventListener('orientationchange', function() {
                refreshMobileViewportFlag();
                setTimeout(function() {
                    baseInnerHeight = window.innerHeight;
                    baseVisualHeight = window.visualViewport ? window.visualViewport.height : window.innerHeight;
                    syncKeyboardOffset();
                }, 80);
            });
        input.addEventListener('focus', function() {
            if (!isMobileViewport) return;
            startKeyboardPolling();
            // 先给保底位移，随后由 syncKeyboardOffset 精确修正为贴键盘
            setKeyboardOffset(Math.max(140, Math.round(window.innerHeight * 0.2)));
            setTimeout(syncKeyboardOffset, 20);
            setTimeout(syncKeyboardOffset, 80);
            setTimeout(syncKeyboardOffset, 180);
            setTimeout(syncKeyboardOffset, 320);
        });
            input.addEventListener('blur', function() {
                if (!isMobileViewport) return;
                stopKeyboardPolling();
                setTimeout(function() {
                    setKeyboardOffset(0);
                }, 80);
            });
            document.addEventListener('visibilitychange', function() {
                if (document.visibilityState !== 'visible') return;
                setTimeout(syncKeyboardOffset, 60);
            });
            refreshMobileViewportFlag();
            syncKeyboardOffset();
        } else {
            input.addEventListener('focus', function() {
                if (!isMobileViewport) return;
                startKeyboardPolling();
                setKeyboardOffset(Math.max(180, Math.round(window.innerHeight * 0.28)));
                scrollToBottom();
            });
            input.addEventListener('blur', function() {
                stopKeyboardPolling();
                setKeyboardOffset(0);
            });
            window.addEventListener('orientationchange', function() {
                baseInnerHeight = window.innerHeight;
                refreshMobileViewportFlag();
                setKeyboardOffset(0);
            });
            refreshMobileViewportFlag();
        }
    })();
    </script>
</body>
</html>
