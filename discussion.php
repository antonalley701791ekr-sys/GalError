<?php
require_once 'includes/user_auth.php';
require_once 'includes/markdown.php';

$pdo = getDB();
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    header('Location: /discussions');
    exit;
}

// 获取话题详情
$stmt = $pdo->prepare("SELECT d.*, u.username, u.avatar FROM discussions d JOIN users u ON d.user_id = u.id WHERE d.id = ? AND d.status = 'active'");
$stmt->execute([$id]);
$discussion = $stmt->fetch();

if (!$discussion) {
    header('Location: /discussions');
    exit;
}

$tags = array_filter(explode(',', $discussion['tags']));

// 获取浏览量
$viewCounts = getViewCount('discussion', $id);

// 获取评论列表（含回复信息）
$commentPage = max(1, intval($_GET['comment_page'] ?? 1));
$commentPerPage = getCommentPerPage();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE content_type = 'discussion' AND content_id = ? AND status = 'active'");
$stmt->execute([$id]);
$commentCount = (int)$stmt->fetchColumn();
$commentPagination = paginate($commentCount, $commentPage, $commentPerPage);

$stmt = $pdo->prepare("
    SELECT c.*, u.username, u.avatar, c.parent_id,
           pc.user_id as parent_user_id, pu.username as parent_username, pc.content as parent_content
    FROM comments c
    JOIN users u ON c.user_id = u.id
    LEFT JOIN comments pc ON c.parent_id = pc.id
    LEFT JOIN users pu ON pc.user_id = pu.id
    WHERE c.content_type = 'discussion' AND c.content_id = ? AND c.status = 'active'
    ORDER BY c.created_at ASC, c.id ASC
    LIMIT {$commentPagination['offset']}, {$commentPerPage}
");
$stmt->execute([$id]);
$comments = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($discussion['title']); ?> - 讨论区 - <?php echo h(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/user.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/markdown-editor.css">
    <style>
        .comment-item {
            transition: background-color .35s ease, box-shadow .35s ease, border-color .35s ease, transform .35s ease;
        }
        .comment-body .comment-reply-header,
        .comment-body .comment-reply-header a,
        .comment-body .comment-reply-header a:hover,
        .comment-body .comment-reply-header a:visited,
        .comment-body .comment-reply-header a:active {
            color: rgba(148, 163, 184, 0.88) !important;
            text-decoration: none !important;
            opacity: 1 !important;
            font-style: italic;
            font-weight: 400;
        }
        .comment-body .comment-reply-header a:hover {
            color: rgba(176, 190, 207, 0.94) !important;
        }
        .comment-body .comment-reply-quote,
        .comment-body .comment-reply-quote:hover,
        .comment-body .comment-reply-quote:visited,
        .comment-body .comment-reply-quote:active {
            color: rgba(148, 163, 184, 0.88) !important;
            text-decoration: none !important;
            opacity: 1 !important;
        }
    </style>
    <?php renderSiteHead(); ?>
</head>
<body class="has-fixed-nav">
    <?php include 'includes/header.php'; ?>

    <main class="main">
        <div class="container" style="max-width: 900px;">
            <!-- 面包屑 -->
            <div class="article-breadcrumb-row mb-20">
                <span>
                    <a href="/">首页</a> &gt;
                    <a href="/discussions">讨论区</a> &gt;
                    <span style="color:var(--text-secondary);"><?php echo h(mb_substr($discussion['title'], 0, 30)); ?></span>
                </span>
                <a href="/discussions" class="btn btn-secondary btn-sm">返回讨论区</a>
            </div>

            <!-- 话题详情 -->
            <article class="card article-detail-card">
                <div class="card-body" style="padding:28px;">
                    <!-- 标题 -->
                    <h1 class="article-detail-title"><?php echo h($discussion['title']); ?></h1>

                    <!-- 元信息 -->
                    <div class="article-detail-meta">
                        <span class="article-author">
                            <?php
                            $avatarUrl = '';
                            if ($discussion['avatar'] && file_exists(BASE_PATH . $discussion['avatar'])) {
                                $avatarUrl = '/' . $discussion['avatar'];
                            }
                            ?>
                            <?php if ($avatarUrl): ?>
                                <img src="<?php echo h($avatarUrl); ?>" class="article-author-avatar" alt="">
                            <?php else: ?>
                                <span class="article-author-avatar fallback"><?php echo h(mb_substr($discussion['username'], 0, 1)); ?></span>
                            <?php endif; ?>
                            <a href="/profile?user_id=<?php echo intval($discussion['user_id']); ?>" class="detail-author-link"><?php echo h($discussion['username']); ?></a>
                        </span>
                        <span><?php echo date('Y-m-d H:i', strtotime($discussion['created_at'])); ?></span>
                        <span class="view-count-detail" title="浏览量">
                            <svg class="view-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            登录浏览：<span id="view-count-user"><?php echo $viewCounts['user_views']; ?></span> | 访客浏览：<span id="view-count-guest"><?php echo $viewCounts['guest_views']; ?></span>
                        </span>
                    </div>

                    <!-- 标签 -->
                    <?php if (!empty($tags)): ?>
                    <div class="article-detail-tags">
                        <?php foreach ($tags as $tag): ?>
                            <a href="/discussions?tag=<?php echo urlencode(trim($tag)); ?>" class="article-tag"><?php echo h(trim($tag)); ?></a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- 内容 -->
                    <div class="article-detail-content markdown-body">
                        <?php echo md_to_html($discussion['content']); ?>
                    </div>

                    <!-- 操作 -->
                    <div class="article-detail-actions">
                        <?php if (isUserLoggedIn() && getCurrentUserId() == $discussion['user_id']): ?>
                            <a href="/submit_discussion?edit=<?php echo $id; ?>" class="btn btn-secondary btn-sm">编辑话题</a>
                        <?php endif; ?>
                        <?php if (isAdmin()): ?>
                            <button class="btn btn-danger btn-sm" onclick="deleteDiscussion(<?php echo $id; ?>)">删除话题</button>
                        <?php endif; ?>
                        <a href="/discussions" class="btn btn-secondary btn-sm">返回讨论区</a>
                    </div>
                </div>
            </article>

            <!-- 评论区 -->
            <section class="comment-section" style="margin-top:24px;">
                <div class="comment-section-header">
                    <h3>评论区（<span id="commentCount"><?php echo $commentCount; ?></span> 条）</h3>
                    <?php if (isUserLoggedIn()): ?>
                        <button class="btn btn-sm" onclick="openCommentModal()">发表评论</button>
                    <?php else: ?>
                        <a href="/login?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="btn btn-sm">登录后评论</a>
                    <?php endif; ?>
                </div>
                <div class="comment-list" id="commentList">
                    <?php if (!empty($comments)): ?>
                        <?php foreach ($comments as $comment): ?>
                            <div class="comment-item" id="comment-<?php echo $comment['id']; ?>" data-comment-content="<?php echo h($comment['content']); ?>">
                                <div class="comment-author">
                                    <span class="comment-author-info">
                                        <?php
                                        $cAvatarUrl = '';
                                        if ($comment['avatar'] && file_exists(BASE_PATH . $comment['avatar'])) {
                                            $cAvatarUrl = '/' . $comment['avatar'];
                                        }
                                        ?>
                                        <?php if ($cAvatarUrl): ?>
                                            <img src="<?php echo h($cAvatarUrl); ?>" class="comment-author-avatar" alt="">
                                        <?php else: ?>
                                            <span class="comment-author-avatar fallback"><?php echo h(mb_substr($comment['username'], 0, 1)); ?></span>
                                        <?php endif; ?>
                                        <strong><a href="/profile?user_id=<?php echo intval($comment['user_id']); ?>" class="comment-user-link"><?php echo h($comment['username']); ?></a></strong>
                                        <?php if ($comment['parent_id'] && $comment['parent_username']): ?>
                                            <span class="comment-reply-to">回复 
                                                <a href="/profile?user_id=<?php echo intval($comment['parent_user_id'] ?? 0); ?>" class="comment-user-link">
                                                    <?php echo h($comment['parent_username']); ?>
                                                </a>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="comment-meta-right">
                                        <span class="comment-time"><?php echo date('Y-m-d H:i', strtotime($comment['created_at'])); ?></span>
                                        <?php if (isUserLoggedIn()): ?>
                                            <button class="comment-reply-btn" data-comment-id="<?php echo $comment['id']; ?>" data-comment-username="<?php echo h($comment['username']); ?>">回复</button>
                                        <?php endif; ?>
                                        <?php if (isUserLoggedIn() && (int)getCurrentUserId() === (int)$comment['user_id']): ?>
                                            <button class="comment-reply-btn comment-edit-btn" data-comment-id="<?php echo $comment['id']; ?>">编辑</button>
                                        <?php endif; ?>
                                        <?php if (isAdmin()): ?>
                                            <button class="comment-delete-btn" data-comment-id="<?php echo $comment['id']; ?>" title="删除评论">删除</button>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="comment-body markdown-body">
                                    <?php if ($comment['parent_id'] && $comment['parent_username']): ?>
                                        <div class="comment-reply-context">
                                            <div class="comment-reply-header">回复 <span class="comment-user-link">@<?php echo h($comment['parent_username']); ?></span></div>
                                            <a href="<?php echo h(buildCommentTargetUrl('discussion', $id, (int)$comment['parent_id'], $commentPerPage)); ?>" class="comment-reply-quote"><?php echo nl2br(h(mb_strimwidth(trim((string)($comment['parent_content'] ?? '')), 0, 240, '…', 'UTF-8'))); ?></a>
                                            <div class="comment-reply-divider"></div>
                                        </div>
                                    <?php endif; ?>
                                    <div class="comment-main-content"><?php echo md_to_html($comment['content']); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="comment-empty" id="commentEmpty">暂无评论，来发表第一条评论吧</div>
                    <?php endif; ?>
                </div>
                <?php if ($commentPagination['totalPages'] > 1): ?>
                    <div class="pagination" style="margin-top:18px;">
                        <?php if ($commentPagination['page'] > 1): ?>
                            <a href="/discussion.php?id=<?php echo $id; ?>&comment_page=1#commentList">第一页</a>
                        <?php endif; ?>
                        <?php if ($commentPagination['hasPrev']): ?>
                            <a href="/discussion.php?id=<?php echo $id; ?>&comment_page=<?php echo $commentPagination['page'] - 1; ?>#commentList">上一页</a>
                        <?php endif; ?>
                        <span class="current"><?php echo $commentPagination['page']; ?> / <?php echo $commentPagination['totalPages']; ?></span>
                        <?php if ($commentPagination['hasNext']): ?>
                            <a href="/discussion.php?id=<?php echo $id; ?>&comment_page=<?php echo $commentPagination['page'] + 1; ?>#commentList">下一页</a>
                        <?php endif; ?>
                        <?php if ($commentPagination['page'] < $commentPagination['totalPages']): ?>
                            <a href="/discussion.php?id=<?php echo $id; ?>&comment_page=<?php echo $commentPagination['totalPages']; ?>#commentList">最后一页</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <!-- 评论弹窗 -->
    <div class="comment-modal-overlay" id="commentModalOverlay" style="display:none;">
        <div class="comment-modal">
            <div class="comment-modal-header">
                <h3><span id="commentModalTitle">发表评论</span><span id="replyIndicator" style="display:none;font-size:0.85rem;color:var(--accent-purple);margin-left:8px;"></span></h3>
                <button class="comment-modal-close" onclick="closeCommentModal()">&times;</button>
            </div>
            <div class="comment-modal-body">
                <div class="md-editor-wrap">
                    <div class="md-mode-tabs">
                        <button type="button" class="md-mode-tab active" data-mode="edit" onclick="switchCommentEditorMode('edit')">编写</button>
                        <button type="button" class="md-mode-tab" data-mode="preview" onclick="switchCommentEditorMode('preview')">预览</button>
                    </div>
                    <div class="md-editor-toolbar" id="commentToolbar">
                        <button type="button" data-action="bold" title="加粗"><b>B</b></button>
                        <button type="button" data-action="italic" title="斜体"><i>I</i></button>
                        <button type="button" data-action="strikethrough" title="删除线"><s>S</s></button>
                        <span class="md-toolbar-separator"></span>
                        <button type="button" data-action="link" title="链接">&#128279;</button>
                        <button type="button" data-action="mention" title="提及用户">@用户</button>
                        <button type="button" data-action="code" title="行内代码">`</button>
                        <button type="button" data-action="codeblock" title="代码块">&lt;/&gt;</button>
                        <span class="md-toolbar-separator"></span>
                        <button type="button" data-action="ul" title="无序列表">&#8226;</button>
                        <button type="button" data-action="ol" title="有序列表">1.</button>
                        <button type="button" data-action="quote" title="引用">&gt;</button>
                    </div>
                    <div class="md-editor-body mode-edit" id="commentEditorBody">
                        <textarea id="commentTextarea" class="md-editor-textarea" placeholder="输入评论内容，支持 Markdown 语法..." style="min-height:200px;"></textarea>
                        <div id="commentPreview" class="md-editor-preview-pane"></div>
                    </div>
                </div>
            </div>
            <div class="comment-modal-footer">
                <button class="btn" id="commentSubmitBtn" onclick="submitComment()">提交评论</button>
                <button class="btn btn-secondary" id="cancelReplyBtn" onclick="cancelReply()" style="display:none;">取消回复</button>
                <button class="btn btn-secondary" onclick="closeCommentModal()">取消</button>
            </div>
        </div>
    </div>

    <?php include 'includes/footer.php'; ?>
    <div id="view-counter-config" data-type="discussion" data-id="<?php echo $id; ?>" style="display:none;"></div>
    <script src="/assets/js/view-counter.js"></script>
    <script src="/assets/js/marked.min.js"></script>
    <script src="/assets/js/comment-collapse.js?v=<?php echo ASSETS_VER; ?>"></script>
    <script src="/assets/js/comment-interactions.js?v=<?php echo ASSETS_VER; ?>"></script>

    <script>
    function commentProxyImageUrl(url) {
        var value = String(url || '').trim();
        if (!value) return '';
        if (/^(?:https?:)?\/\//i.test(value)) {
            if (value.indexOf('//') === 0) {
                value = window.location.protocol + value;
            }
            return '/image_proxy?url=' + encodeURIComponent(value);
        }
        return value;
    }

    function commentNormalizePlainImageUrls(text) {
        return String(text || '').replace(
            /(^|[\s>\(])((?:https?:)?\/\/[^\s<]+\.(?:jpg|jpeg|png|gif|webp|svg)(?:\?[^\s<]*)?(?:#[^\s<]*)?)(?=$|[\s<\)])/gi,
            function(match, prefix, url) {
                return prefix + '![](' + url + ')';
            }
        );
    }

    function commentPreserveExtraBlankLinesForPreview(text) {
        return String(text || '').replace(/\n{3,}/g, function(match) {
            var extraCount = match.length - 2;
            var result = '\n\n';
            for (var i = 0; i < extraCount; i++) {
                result += '@@MD_EXTRA_BLANK_LINE@@\n';
            }
            return result;
        });
    }

    function commentRestoreExtraBlankLinesPreviewHtml(html) {
        var out = String(html || '');
        out = out.replace(/<p>@@MD_EXTRA_BLANK_LINE@@<\/p>/g, '<p class="md-extra-blank-line" aria-hidden="true"><br></p>');
        out = out.replace(/@@MD_EXTRA_BLANK_LINE@@<br\s*\/?\s*>/g, '<br>');
        out = out.replace(/@@MD_EXTRA_BLANK_LINE@@/g, '<br>');
        return out;
    }

    // 配置 marked：链接和图片在新标签页打开
    (function() {
        if (typeof marked === 'undefined') return;
        var renderer = new marked.Renderer();
        renderer.link = function(token) {
            var href = token.href || '';
            var title = token.title || '';
            var text = token.text || href;
            var titleAttr = title ? ' title="' + escapeHtml(title) + '"' : '';
            return '<a href="' + escapeHtml(href) + '"' + titleAttr + ' target="_blank" rel="noopener noreferrer">' + text + '</a>';
        };
        renderer.image = function(token) {
            var href = token.href || '';
            var title = token.title || '';
            var text = token.text || '';
            var titleAttr = title ? ' title="' + escapeHtml(title) + '"' : '';
            var src = commentProxyImageUrl(href);
            return '<img src="' + escapeHtml(src) + '" alt="' + escapeHtml(text) + '"' + titleAttr + ' style="max-width:100%;">';
        };
        marked.setOptions({ breaks: true, renderer: renderer });
    })();

    // ========== 评论弹窗 Markdown 编辑器（独立实例） ==========
    var CommentEditor = (function() {
        var editor = null;
        var preview = null;
        var mentionState = createMentionState();
        var orderedListHandledAt = 0;

        function createMentionState() {
            return {
                popup: null,
                list: [],
                selectedIndex: 0,
                visible: false,
                activeRange: null,
                requestToken: 0,
                inputTimer: null
            };
        }

        function init() {
            editor = document.getElementById('commentTextarea');
            preview = document.getElementById('commentPreview');
            if (!editor) return;

            // 工具栏
            document.getElementById('commentToolbar').addEventListener('click', function(e) {
                var btn = e.target.closest('button[data-action]');
                if (!btn) return;
                handleAction(btn.getAttribute('data-action'));
                editor.focus();
            });

            // Tab 键支持
            editor.addEventListener('beforeinput', function(e) {
                if (!e || e.inputType !== 'insertLineBreak') return;
                if (handleOrderedListContinue()) {
                    orderedListHandledAt = Date.now();
                    e.preventDefault();
                }
            });

            editor.addEventListener('keydown', function(e) {
                if (mentionState.visible) {
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        moveMentionSelection(1);
                        return;
                    }
                    if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        moveMentionSelection(-1);
                        return;
                    }
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        chooseMentionSelection();
                        return;
                    }
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        hideMentionAutocomplete();
                        return;
                    }
                }
                if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.altKey && !e.metaKey) {
                    if (Date.now() - orderedListHandledAt < 80) {
                        e.preventDefault();
                        return;
                    }
                    if (handleOrderedListContinue()) {
                        orderedListHandledAt = Date.now();
                        e.preventDefault();
                        return;
                    }
                }
                if (e.key === 'Tab') {
                    e.preventDefault();
                    insertAtCursor('    ');
                }
            });

            editor.addEventListener('input', function() {
                clearTimeout(mentionState.inputTimer);
                mentionState.inputTimer = setTimeout(syncMentionAutocomplete, 120);
            });
            editor.addEventListener('click', syncMentionAutocomplete);
            editor.addEventListener('keyup', function(e) {
                if (e.key === 'ArrowUp' || e.key === 'ArrowDown' || e.key === 'Enter' || e.key === 'Escape') return;
                syncMentionAutocomplete();
            });

            ensureMentionAutocomplete();
            document.addEventListener('click', function(e) {
                if (!mentionState.popup) return;
                if (e.target === editor || mentionState.popup.contains(e.target)) return;
                hideMentionAutocomplete();
            });
            window.addEventListener('resize', positionMentionAutocomplete);
            window.addEventListener('scroll', positionMentionAutocomplete, true);
        }

        function handleAction(action) {
            switch (action) {
                case 'bold': wrapSelection('**', '**', '粗体文本'); break;
                case 'italic': wrapSelection('*', '*', '斜体文本'); break;
                case 'strikethrough': wrapSelection('~~', '~~', '删除线文本'); break;
                case 'link':
                    var url = prompt('请输入链接地址:', 'https://');
                    if (!url) return;
                    var s = editor.selectionStart, e2 = editor.selectionEnd;
                    var sel = editor.value.substring(s, e2);
                    if (sel) {
                        var ins = '[' + sel + '](' + url + ')';
                        editor.value = editor.value.substring(0, s) + ins + editor.value.substring(e2);
                    } else {
                        editor.value = editor.value.substring(0, s) + url + editor.value.substring(e2);
                    }
                    break;
                case 'mention':
                    var mentionName = prompt('请输入要提及的站内用户名:', '');
                    if (mentionName === null) return;
                    mentionName = String(mentionName).trim().replace(/^@+/, '');
                    if (!mentionName) return;
                    insertAtCursor('@' + mentionName + ' ');
                    break;
                case 'code': wrapSelection('`', '`', '代码'); break;
                case 'codeblock':
                    var s2 = editor.selectionStart;
                    var pre = s2 > 0 && editor.value[s2-1] !== '\n' ? '\n' : '';
                    var ins2 = pre + '```\n代码内容\n```\n';
                    editor.value = editor.value.substring(0, s2) + ins2 + editor.value.substring(s2);
                    break;
                case 'ul': insertAtLineStart('- '); break;
                case 'ol': insertAtLineStart('1. '); break;
                case 'quote': insertAtLineStart('> '); break;
            }
        }

        function ensureMentionAutocomplete() {
            if (mentionState.popup || !editor) return;
            var popup = document.createElement('div');
            popup.className = 'md-mention-popup';
            popup.style.display = 'none';
            popup.innerHTML = '<div class="md-mention-list"></div>';
            document.body.appendChild(popup);
            popup.addEventListener('mousedown', function(e) {
                var item = e.target.closest('.md-mention-item');
                if (!item) return;
                e.preventDefault();
                mentionState.selectedIndex = parseInt(item.getAttribute('data-index') || '0', 10) || 0;
                chooseMentionSelection();
            });
            mentionState.popup = popup;
        }

        function syncMentionAutocomplete() {
            var info = getActiveMentionQuery();
            if (!info || !info.query) {
                hideMentionAutocomplete();
                return;
            }
            mentionState.activeRange = info;
            fetchMentionSuggestions(info.query);
        }

        function getActiveMentionQuery() {
            var cursor = editor.selectionStart;
            if (cursor !== editor.selectionEnd) return null;
            var beforeCursor = editor.value.slice(0, cursor);
            var match = beforeCursor.match(/(^|[^\u4e00-\u9fa5A-Za-z0-9_])@([\u4e00-\u9fa5A-Za-z0-9_]*)$/);
            if (!match) return null;
            return { query: match[2], start: cursor - match[2].length - 1, end: cursor };
        }

        function fetchMentionSuggestions(query) {
            var currentToken = ++mentionState.requestToken;
            fetch('/api/mention.php?q=' + encodeURIComponent(query), { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (currentToken !== mentionState.requestToken) return;
                    if (!data || !data.success || !Array.isArray(data.users) || !data.users.length) {
                        hideMentionAutocomplete();
                        return;
                    }
                    mentionState.list = data.users;
                    mentionState.selectedIndex = 0;
                    renderMentionAutocomplete();
                })
                .catch(function() {
                    if (currentToken !== mentionState.requestToken) return;
                    hideMentionAutocomplete();
                });
        }

        function renderMentionAutocomplete() {
            if (!mentionState.popup || !mentionState.list.length) {
                hideMentionAutocomplete();
                return;
            }
            mentionState.popup.querySelector('.md-mention-list').innerHTML = mentionState.list.map(function(user, index) {
                return '<button type="button" class="md-mention-item' + (index === mentionState.selectedIndex ? ' is-active' : '') + '" data-index="' + index + '">@' + escapeHtml(user.username) + '</button>';
            }).join('');
            positionMentionAutocomplete();
            mentionState.popup.style.display = 'block';
            mentionState.visible = true;
        }

        function positionMentionAutocomplete() {
            if (!mentionState.popup || !editor) return;
            var rect = editor.getBoundingClientRect();
            mentionState.popup.style.left = (window.scrollX + rect.left + 16) + 'px';
            mentionState.popup.style.top = (window.scrollY + rect.bottom - 12) + 'px';
            mentionState.popup.style.width = Math.min(rect.width - 32, 320) + 'px';
        }

        function moveMentionSelection(step) {
            if (!mentionState.visible || !mentionState.list.length) return;
            var total = mentionState.list.length;
            mentionState.selectedIndex = (mentionState.selectedIndex + step + total) % total;
            renderMentionAutocomplete();
        }

        function chooseMentionSelection() {
            if (!mentionState.visible || !mentionState.list.length || !mentionState.activeRange) return;
            var selected = mentionState.list[mentionState.selectedIndex];
            if (!selected) return;
            var replacement = '@' + selected.username + ' ';
            editor.value = editor.value.slice(0, mentionState.activeRange.start) + replacement + editor.value.slice(mentionState.activeRange.end);
            var newPos = mentionState.activeRange.start + replacement.length;
            editor.selectionStart = editor.selectionEnd = newPos;
            hideMentionAutocomplete();
            editor.focus();
        }

        function hideMentionAutocomplete() {
            mentionState.visible = false;
            mentionState.list = [];
            mentionState.activeRange = null;
            if (mentionState.popup) mentionState.popup.style.display = 'none';
        }

        function wrapSelection(before, after, defaultText) {
            var s = editor.selectionStart, e2 = editor.selectionEnd;
            var sel = editor.value.substring(s, e2);
            if (sel) {
                editor.value = editor.value.substring(0, s) + before + sel + after + editor.value.substring(e2);
                editor.selectionStart = s + before.length;
                editor.selectionEnd = e2 + before.length;
            } else {
                var ins = before + defaultText + after;
                editor.value = editor.value.substring(0, s) + ins + editor.value.substring(e2);
                editor.selectionStart = s + before.length;
                editor.selectionEnd = s + before.length + defaultText.length;
            }
        }

        function insertAtLineStart(prefix) {
            var s = editor.selectionStart;
            var lineStart = editor.value.lastIndexOf('\n', s - 1) + 1;
            editor.value = editor.value.substring(0, lineStart) + prefix + editor.value.substring(lineStart);
            editor.selectionStart = editor.selectionEnd = s + prefix.length;
        }

        function insertAtCursor(text) {
            var s = editor.selectionStart;
            editor.value = editor.value.substring(0, s) + text + editor.value.substring(s);
            editor.selectionStart = editor.selectionEnd = s + text.length;
        }

        function handleOrderedListContinue() {
            if (!editor) return false;
            var start = editor.selectionStart;
            var end = editor.selectionEnd;
            if (start !== end) return false;

            var text = editor.value;
            var lineStart = text.lastIndexOf('\n', start - 1) + 1;
            var lineEnd = text.indexOf('\n', start);
            if (lineEnd === -1) lineEnd = text.length;

            var line = text.substring(lineStart, lineEnd);
            var match = line.match(/^(\s*)(\d+)\.\s*(.*)$/);
            if (!match) return false;

            var indent = match[1] || '';
            var number = parseInt(match[2], 10);
            if (!isFinite(number)) return false;
            var content = match[3] || '';

            if (!content.trim()) {
                var removeUntil = lineEnd;
                if (removeUntil < text.length && text.charAt(removeUntil) === '\n') {
                    removeUntil += 1;
                }
                editor.value = text.substring(0, lineStart) + text.substring(removeUntil);
                editor.selectionStart = editor.selectionEnd = lineStart;
                return true;
            }

            var contentStartInLine = match[0].length - content.length;
            var cursorInContent = start - (lineStart + contentStartInLine);
            if (cursorInContent < 0) cursorInContent = 0;
            if (cursorInContent > content.length) cursorInContent = content.length;

            var beforeContent = content.slice(0, cursorInContent);
            var afterContent = content.slice(cursorInContent);

            var currentPrefix = indent + number + '. ';
            var nextPrefix = indent + (number + 1) + '. ';

            var newCurrentLine = currentPrefix + beforeContent;
            var newNextLine = nextPrefix + afterContent.replace(/^\s+/, '');

            var replacement = newCurrentLine + '\n' + newNextLine;
            editor.value = text.substring(0, lineStart) + replacement + text.substring(lineEnd);

            var newPos = lineStart + newCurrentLine.length + 1 + nextPrefix.length;
            editor.selectionStart = editor.selectionEnd = newPos;
            return true;
        }

        function getValue() { return editor ? editor.value : ''; }
        function setValue(value) { if (editor) editor.value = value || ''; if (preview) preview.innerHTML = ''; }
        function clear() { if (editor) editor.value = ''; if (preview) preview.innerHTML = ''; }

        function renderPreview() {
            if (!preview || typeof marked === 'undefined') return;
            var text = commentNormalizePlainImageUrls(editor.value);
            text = commentPreserveExtraBlankLinesForPreview(text);
            if (text) {
                var html = marked.parse(text);
                preview.innerHTML = '<div class="markdown-body">' + commentRestoreExtraBlankLinesPreviewHtml(html) + '</div>';
            } else {
                preview.innerHTML = '<div class="md-preview-empty">预览区域</div>';
            }
        }

        return { init: init, getValue: getValue, setValue: setValue, clear: clear, renderPreview: renderPreview };
    })();

    // 初始化评论编辑器
    CommentEditor.init();

    function switchCommentEditorMode(mode) {
        var tabs = document.querySelectorAll('#commentModalOverlay .md-mode-tab');
        tabs.forEach(function(t) { t.classList.remove('active'); });
        tabs.forEach(function(t) { if (t.getAttribute('data-mode') === mode) t.classList.add('active'); });
        var body = document.getElementById('commentEditorBody');
        body.className = 'md-editor-body mode-' + mode;
        if (mode === 'preview') CommentEditor.renderPreview();
    }

    // ========== 回复与编辑功能 ==========
    window._replyParentId = 0;
    window._editingCommentId = 0;

    function setCommentModalMode(mode) {
        var title = document.getElementById('commentModalTitle');
        var submitBtn = document.getElementById('commentSubmitBtn');
        if (title) title.textContent = mode === 'edit' ? '编辑评论' : '发表评论';
        if (submitBtn) submitBtn.textContent = mode === 'edit' ? '保存修改' : '提交评论';
    }

    function replyToComment(commentId, username) {
        window._editingCommentId = 0;
        window._replyParentId = commentId;
        setCommentModalMode('create');
        CommentEditor.clear();
        var indicator = document.getElementById('replyIndicator');
        indicator.textContent = '回复 ' + username;
        indicator.style.display = 'inline';
        document.getElementById('cancelReplyBtn').style.display = '';
        openCommentModal();
    }

    function editComment(commentId) {
        var item = document.getElementById('comment-' + commentId);
        if (!item) return;
        window._replyParentId = 0;
        window._editingCommentId = commentId;
        setCommentModalMode('edit');
        CommentEditor.setValue(item.getAttribute('data-comment-content') || '');
        var indicator = document.getElementById('replyIndicator');
        indicator.style.display = 'none';
        indicator.textContent = '';
        document.getElementById('cancelReplyBtn').style.display = 'none';
        openCommentModal();
    }

    function cancelReply() {
        window._replyParentId = 0;
        var indicator = document.getElementById('replyIndicator');
        indicator.style.display = 'none';
        indicator.textContent = '';
        document.getElementById('cancelReplyBtn').style.display = 'none';
    }

    // ========== 弹窗控制 ==========
    function openCommentModal() {
        if (!window._replyParentId && !window._editingCommentId) {
            setCommentModalMode('create');
            CommentEditor.clear();
            var indicator = document.getElementById('replyIndicator');
            indicator.style.display = 'none';
            indicator.textContent = '';
            document.getElementById('cancelReplyBtn').style.display = 'none';
        }
        document.getElementById('commentModalOverlay').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeCommentModal() {
        document.getElementById('commentModalOverlay').style.display = 'none';
        document.body.style.overflow = '';
        window._replyParentId = 0;
        window._editingCommentId = 0;
        setCommentModalMode('create');
        var indicator = document.getElementById('replyIndicator');
        indicator.style.display = 'none';
        indicator.textContent = '';
        document.getElementById('cancelReplyBtn').style.display = 'none';
    }



    // ========== 提交评论 ==========
    function submitComment() {
        var rawContent = CommentEditor.getValue();
        if (!rawContent || !rawContent.trim()) {
            alert('请输入评论内容');
            return;
        }

        var content = rawContent;

        var btn = document.getElementById('commentSubmitBtn');
        btn.disabled = true;
        btn.textContent = '提交中...';

        if (window._editingCommentId) {
            fetch('/api/comment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'update',
                    id: window._editingCommentId,
                    content: content
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.disabled = false;
                btn.textContent = '保存修改';
                if (!data.success) {
                    alert(data.message || '保存失败');
                    return;
                }
                var item = document.getElementById('comment-' + window._editingCommentId);
                if (item) {
                    item.setAttribute('data-comment-content', data.comment.content || content);
                    var contentEl = item.querySelector('.comment-main-content');
                    if (contentEl) {
                        contentEl.innerHTML = data.comment.content_html || '';
                        if (window.initCommentCollapses) window.initCommentCollapses(item);
                    }
                }
                CommentEditor.clear();
                closeCommentModal();
            })
            .catch(function() {
                btn.disabled = false;
                btn.textContent = '保存修改';
                alert('网络错误，请稍后重试');
            });
            return;
        }

        fetch('/api/comment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create',
                content_type: 'discussion',
                content_id: <?php echo $id; ?>,
                content: content,
                parent_id: window._replyParentId || null
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            btn.disabled = false;
            btn.textContent = '提交评论';
            if (!data.success) {
                alert(data.message || '提交失败');
                return;
            }
            window._replyParentId = 0;
            CommentEditor.clear();
            closeCommentModal();
            var redirectUrl = data.redirect_url || (window.location.pathname + window.location.search + (data.comment && data.comment.id ? ('#comment-' + data.comment.id) : ''));
            window.location.assign(redirectUrl);
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = '提交评论';
            alert('网络错误，请稍后重试');
        });
    }

    // ========== 删除评论 ==========
    function deleteComment(commentId) {
        if (!confirm('确定删除此评论？')) return;

        fetch('/api/comment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id: commentId })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                var el = document.getElementById('comment-' + commentId);
                if (el) {
                    el.style.opacity = '0';
                    el.style.transition = 'opacity 0.3s';
                    setTimeout(function() { el.remove(); }, 300);
                }
                var countEl = document.getElementById('commentCount');
                var newCount = Math.max(0, parseInt(countEl.textContent, 10) - 1);
                countEl.textContent = newCount;
                if (newCount === 0) {
                    document.getElementById('commentList').innerHTML = '<div class="comment-empty" id="commentEmpty">暂无评论，来发表第一条评论吧</div>';
                }
            } else {
                alert(data.message || '删除失败');
            }
        })
        .catch(function() { alert('网络错误'); });
    }

    // ========== 删除话题 ==========
    function deleteDiscussion(discId) {
        if (!confirm('确定删除此话题？删除后不可恢复。')) return;

        fetch('/api/discussion.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id: discId })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                window.location.href = '/discussions';
            } else {
                alert(data.message || '删除失败');
            }
        })
        .catch(function() { alert('网络错误'); });
    }

    if (window.GalCommentInteractions && typeof window.GalCommentInteractions.init === 'function') {
        window.GalCommentInteractions.init({
            onReply: replyToComment,
            onEdit: editComment,
            onDelete: deleteComment
        });
    }

    function escapeHtml(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }
    </script>
</body>
</html>
