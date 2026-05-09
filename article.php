<?php
require_once 'includes/user_auth.php';
require_once 'includes/markdown.php';

$pdo = getDB();
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    header('Location: /articles');
    exit;
}

// 获取文章详情（仅已通过的文章可查看，管理员可查看所有）
$sql = "SELECT a.*, u.username, u.avatar FROM articles a JOIN users u ON a.user_id = u.id WHERE a.id = ?";
if (!isAdmin()) {
    $sql .= " AND a.status = 'approved'";
}
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$article = $stmt->fetch();

if (!$article) {
    header('Location: /articles');
    exit;
}

$tags = array_filter(explode(',', $article['tags']));

// 获取浏览量
$viewCounts = getViewCount('article', $id);

// 获取评论列表（含回复信息）
$commentPage = max(1, intval($_GET['comment_page'] ?? 1));
$commentPerPage = getCommentPerPage();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE content_type = 'article' AND content_id = ? AND status = 'active'");
$stmt->execute([$id]);
$articleCommentCount = (int)$stmt->fetchColumn();
$commentPagination = paginate($articleCommentCount, $commentPage, $commentPerPage);

$stmt = $pdo->prepare("
    SELECT c.*, u.username, u.avatar, c.parent_id,
           pc.user_id as parent_user_id, pu.username as parent_username, pc.content as parent_content
    FROM comments c
    JOIN users u ON c.user_id = u.id
    LEFT JOIN comments pc ON c.parent_id = pc.id
    LEFT JOIN users pu ON pc.user_id = pu.id
    WHERE c.content_type = 'article' AND c.content_id = ? AND c.status = 'active'
    ORDER BY c.created_at ASC, c.id ASC
    LIMIT {$commentPagination['offset']}, {$commentPerPage}
");
$stmt->execute([$id]);
$articleComments = $stmt->fetchAll();

// 准备 TOC 数据
$tocItems = [];
$tocConfig = json_decode($article['toc_config'] ?? '', true);
if (is_array($tocConfig) && !empty($tocConfig)) {
    // 使用已保存的 TOC 配置，过滤出 visible 的条目
    foreach ($tocConfig as $item) {
        if (!empty($item['visible'])) {
            $tocItems[] = $item;
        }
    }
} else {
    // 旧文章无 TOC 配置，自动从内容提取，全部可见
    $tocItems = extract_headings_from_markdown($article['content']);
}
$hasToc = !empty($tocItems);

// 为 TOC 条目生成层级序号（1、1.1、1.1.1）
if ($hasToc) {
    $minLevel = PHP_INT_MAX;
    foreach ($tocItems as $item) {
        $minLevel = min($minLevel, intval($item['level']));
    }
    $counters = [];
    foreach ($tocItems as &$item) {
        $depth = intval($item['level']) - $minLevel;
        if (!isset($counters[$depth])) {
            $counters[$depth] = 0;
        }
        $counters[$depth]++;
        // 重置更深层级的计数器
        foreach (array_keys($counters) as $d) {
            if ($d > $depth) unset($counters[$d]);
        }
        $parts = [];
        for ($i = 0; $i <= $depth; $i++) {
            $parts[] = isset($counters[$i]) ? $counters[$i] : 1;
        }
        $item['number'] = implode('.', $parts);
    }
    unset($item);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($article['title']); ?> - <?php echo h(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/user.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/markdown-editor.css">
    <?php renderSiteHead(); ?>
</head>
<body class="has-fixed-nav">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <main class="main">
        <div class="article-detail-container">
                <!-- 面包屑 -->
                <div class="article-breadcrumb-row mb-20">
                    <span>
                        <a href="/">首页</a> &gt; <a href="/articles">文章列表</a> &gt; <span style="color:var(--text-secondary);"><?php echo h(mb_substr($article['title'], 0, 30)); ?></span>
                    </span>
                    <a href="/articles" class="btn btn-secondary btn-sm">返回列表</a>
                </div>

                <?php if (!empty($article['has_pending_revision'])): ?>
                    <div class="article-revision-notice">此文章有修改正在审核中，当前显示的是已审核通过的版本。</div>
                <?php endif; ?>

                <article class="card article-detail-card">
                    <div class="card-body" style="padding:28px;">
                        <!-- 标题 -->
                        <h1 class="article-detail-title"><?php echo h($article['title']); ?></h1>

                        <!-- 元信息 -->
                        <div class="article-detail-meta">
                            <span class="article-author">
                                <?php
                                $avatarUrl = '';
                                if ($article['avatar'] && file_exists(BASE_PATH . $article['avatar'])) {
                                    $avatarUrl = '/' . $article['avatar'];
                                }
                                ?>
                                <?php if ($avatarUrl): ?>
                                    <img src="<?php echo h($avatarUrl); ?>" class="article-author-avatar" alt="">
                                <?php else: ?>
                                    <span class="article-author-avatar fallback"><?php echo h(mb_substr($article['username'], 0, 1)); ?></span>
                                <?php endif; ?>
                                <a href="/profile?user_id=<?php echo intval($article['user_id']); ?>" class="detail-author-link"><?php echo h($article['username']); ?></a>
                            </span>
                            <span><?php echo date('Y-m-d H:i', strtotime($article['created_at'])); ?></span>
                            <span class="view-count-detail" title="浏览量">
                                <svg class="view-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                登录浏览：<span id="view-count-user"><?php echo $viewCounts['user_views']; ?></span> | 访客浏览：<span id="view-count-guest"><?php echo $viewCounts['guest_views']; ?></span>
                            </span>
                            <?php if ($article['status'] !== 'approved'): ?>
                                <span class="status status-<?php echo $article['status']; ?>">
                                    <?php echo $article['status'] === 'pending' ? '待审核' : '已驳回'; ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- 标签 -->
                        <?php if (!empty($tags)): ?>
                        <div class="article-detail-tags">
                            <?php foreach ($tags as $tag): ?>
                                <a href="/search?type=article&q=<?php echo urlencode(trim($tag)); ?>" class="article-tag"><?php echo h(trim($tag)); ?></a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <!-- 内容 -->
                        <div class="article-detail-content markdown-body">
                            <?php echo md_to_html($article['content']); ?>
                        </div>

                        <!-- 操作 -->
                        <div class="article-detail-actions">
                            <?php if (isUserLoggedIn() && getCurrentUserId() == $article['user_id']): ?>
                                <?php if ($article['status'] === 'pending' || $article['status'] === 'rejected'): ?>
                                    <a href="/submit_article?edit=<?php echo $article['id']; ?>" class="btn btn-secondary btn-sm">编辑文章</a>
                                <?php elseif ($article['status'] === 'approved' && empty($article['has_pending_revision'])): ?>
                                    <a href="/submit_article?edit=<?php echo $article['id']; ?>" class="btn btn-secondary btn-sm">编辑文章</a>
                                <?php elseif ($article['status'] === 'approved' && !empty($article['has_pending_revision'])): ?>
                                    <span class="btn btn-secondary btn-sm" style="opacity:0.5;cursor:not-allowed;">修改审核中</span>
                                <?php endif; ?>
                            <?php endif; ?>
                            <a href="/articles" class="btn btn-secondary btn-sm">返回列表</a>
                        </div>
                    </div>
                </article>

                <!-- 评论区 -->
                <section class="comment-section" style="margin-top:24px;">
                    <div class="comment-section-header">
                        <h3>评论区（<span id="commentCount"><?php echo $articleCommentCount; ?></span> 条）</h3>
                        <?php if (isUserLoggedIn()): ?>
                            <button class="btn btn-sm" onclick="openCommentModal()">发表评论</button>
                        <?php else: ?>
                            <a href="/login?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="btn btn-sm">登录后评论</a>
                        <?php endif; ?>
                    </div>
                    <div class="comment-list" id="commentList">
                        <?php if (!empty($articleComments)): ?>
                            <?php foreach ($articleComments as $comment): ?>
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
                                                <a href="<?php echo h(buildCommentTargetUrl('article', $id, (int)$comment['parent_id'], $commentPerPage)); ?>" class="comment-reply-quote"><?php echo nl2br(h(mb_strimwidth(trim((string)($comment['parent_content'] ?? '')), 0, 240, '…', 'UTF-8'))); ?></a>
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
                                <a href="/article.php?id=<?php echo $id; ?>&comment_page=1#commentList">第一页</a>
                            <?php endif; ?>
                            <?php if ($commentPagination['hasPrev']): ?>
                                <a href="/article.php?id=<?php echo $id; ?>&comment_page=<?php echo $commentPagination['page'] - 1; ?>#commentList">上一页</a>
                            <?php endif; ?>
                            <span class="current"><?php echo $commentPagination['page']; ?> / <?php echo $commentPagination['totalPages']; ?></span>
                            <?php if ($commentPagination['hasNext']): ?>
                                <a href="/article.php?id=<?php echo $id; ?>&comment_page=<?php echo $commentPagination['page'] + 1; ?>#commentList">下一页</a>
                            <?php endif; ?>
                            <?php if ($commentPagination['page'] < $commentPagination['totalPages']): ?>
                                <a href="/article.php?id=<?php echo $id; ?>&comment_page=<?php echo $commentPagination['totalPages']; ?>#commentList">最后一页</a>
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

    <?php if ($hasToc): ?>
    <aside class="article-toc-fixed" id="tocFixed">
        <div class="article-toc-card" id="tocCard">
            <div class="article-toc-title article-toc-mobile-toggle" id="tocToggle">目录</div>
            <nav class="article-toc-nav" id="tocNav">
                <ul>
                    <?php foreach ($tocItems as $item): ?>
                        <li>
                            <a href="#<?php echo h($item['id']); ?>" class="toc-level-<?php echo intval($item['level']); ?>">
                                <span class="toc-indicator"></span>
                                <span class="toc-number"><?php echo h($item['number']); ?></span>
                                <span class="toc-text"><?php echo h($item['text']); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        </div>
    </aside>
    <?php endif; ?>

    <?php include __DIR__ . '/includes/footer.php'; ?>
    <div id="view-counter-config" data-type="article" data-id="<?php echo $id; ?>" style="display:none;"></div>
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
            document.getElementById('commentToolbar').addEventListener('click', function(e) {
                var btn = e.target.closest('button[data-action]');
                if (!btn) return;
                handleAction(btn.getAttribute('data-action'));
                editor.focus();
            });
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
                    editor.value = editor.value.substring(0, s) + (sel ? '[' + sel + '](' + url + ')' : url) + editor.value.substring(e2);
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
                    var pre = s2 > 0 && editor.value[s2 - 1] !== '\n' ? '\n' : '';
                    editor.value = editor.value.substring(0, s2) + pre + '```\n代码内容\n```\n' + editor.value.substring(s2);
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
            var ins = sel ? before + sel + after : before + defaultText + after;
            editor.value = editor.value.substring(0, s) + ins + editor.value.substring(e2);
            editor.selectionStart = s + before.length;
            editor.selectionEnd = s + before.length + (sel ? sel.length : defaultText.length);
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

    CommentEditor.init();

    function switchCommentEditorMode(mode) {
        var tabs = document.querySelectorAll('#commentModalOverlay .md-mode-tab');
        tabs.forEach(function(t) { t.classList.remove('active'); });
        tabs.forEach(function(t) { if (t.getAttribute('data-mode') === mode) t.classList.add('active'); });
        var body = document.getElementById('commentEditorBody');
        body.className = 'md-editor-body mode-' + mode;
        if (mode === 'preview') CommentEditor.renderPreview();
    }

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
    function openCommentModal() {
        if (!window._replyParentId && !window._editingCommentId) {
            setCommentModalMode('create');
            CommentEditor.clear();
            cancelReply();
        }
        document.getElementById('commentModalOverlay').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    function closeCommentModal() {
        document.getElementById('commentModalOverlay').style.display = 'none';
        document.body.style.overflow = '';
        window._editingCommentId = 0;
        setCommentModalMode('create');
        cancelReply();
    }
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
                body: JSON.stringify({ action: 'update', id: window._editingCommentId, content: content })
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
                content_type: 'article',
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
    function deleteComment(commentId) {
        if (!confirm('确定删除此评论？')) return;
        fetch('/api/comment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id: commentId })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                alert(data.message || '删除失败');
                return;
            }
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

    <?php if ($hasToc): ?>
    <script>
    (function() {
        var navHeight = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--nav-height')) || 64;

        // 平滑滚动 + 偏移
        document.getElementById('tocNav').addEventListener('click', function(e) {
            var link = e.target.closest('a');
            if (!link) return;
            e.preventDefault();
            var targetId = link.getAttribute('href').substring(1);
            var target = document.getElementById(targetId);
            if (target) {
                var top = target.getBoundingClientRect().top + window.pageYOffset - navHeight - 20;
                window.scrollTo({ top: top, behavior: 'smooth' });
            }
        });

        // 收集标题元素与对应 TOC 链接
        var headings = [];
        var tocLinks = document.querySelectorAll('#tocNav a');
        tocLinks.forEach(function(link) {
            var id = link.getAttribute('href').substring(1);
            var el = document.getElementById(id);
            if (el) headings.push({ el: el, link: link });
        });

        // 滚动高亮：基于滚动位置判断当前可见标题
        if (headings.length > 0) {
            var ticking = false;
            function updateActiveHeading() {
                var scrollTop = window.pageYOffset;
                var threshold = navHeight + 40;
                var currentIdx = -1;

                for (var i = headings.length - 1; i >= 0; i--) {
                    if (headings[i].el.getBoundingClientRect().top + window.pageYOffset - threshold <= scrollTop) {
                        currentIdx = i;
                        break;
                    }
                }

                tocLinks.forEach(function(l) { l.classList.remove('active'); });
                if (currentIdx >= 0) {
                    headings[currentIdx].link.classList.add('active');
                    // 滚动 TOC 卡片使活跃项可见
                    var tocCard = document.getElementById('tocCard');
                    var activeLink = headings[currentIdx].link;
                    if (tocCard && activeLink) {
                        var linkTop = activeLink.offsetTop - tocCard.offsetTop;
                        var cardScroll = tocCard.scrollTop;
                        var cardHeight = tocCard.clientHeight;
                        if (linkTop < cardScroll + 40 || linkTop > cardScroll + cardHeight - 40) {
                            tocCard.scrollTop = linkTop - cardHeight / 3;
                        }
                    }
                }
                ticking = false;
            }

            window.addEventListener('scroll', function() {
                if (!ticking) {
                    ticking = true;
                    requestAnimationFrame(updateActiveHeading);
                }
            }, { passive: true });
            updateActiveHeading();
        }

        // 移动端折叠
        var tocToggle = document.getElementById('tocToggle');
        var tocCard = document.getElementById('tocCard');
        if (tocToggle && tocCard && window.innerWidth <= 768) {
            tocCard.classList.add('collapsed');
            tocToggle.addEventListener('click', function() {
                tocCard.classList.toggle('collapsed');
            });
        }
    })();
    </script>
    <?php endif; ?>
</body>
</html>
