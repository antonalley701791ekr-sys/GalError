<?php
require_once 'includes/user_auth.php';
require_once 'includes/markdown.php';
require_once 'includes/sensitive_filter.php';

// 必须登录
requireUserLogin();

if (checkUserBanned()) {
    header('Location: /');
    exit;
}

$pdo = getDB();
$message = '';
$messageType = '';

// 默认标签列表
$defaultTags = ['求助', '讨论', '分享', '报错问题', '汉化问题', '经验交流', '闲聊', '建议'];

// 编辑模式识别
$editId = intval($_GET['edit'] ?? 0);
$editDiscussion = null;
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM discussions WHERE id = ? AND user_id = ? AND status = 'active'");
    $stmt->execute([$editId, getCurrentUserId()]);
    $editDiscussion = $stmt->fetch();
    if (!$editDiscussion) {
        $editId = 0;
    }
}

// 处理图片上传 (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_image') {
    header('Content-Type: application/json');
    if (!isset($_FILES['image'])) {
        echo json_encode(['success' => false, 'message' => '未选择文件']);
        exit;
    }
    $result = handleArticleImageUpload($_FILES['image']);
    echo json_encode($result);
    exit;
}

// 处理提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $title = trim($_POST['title'] ?? '');
    $content = $_POST['content'] ?? '';
    $tags = $_POST['tags'] ?? [];
    $customTag = trim($_POST['custom_tag'] ?? '');

    // 处理自定义标签
    if ($customTag) {
        $customTags = array_map('trim', explode(',', $customTag));
        $customTags = array_filter($customTags);
        $tags = array_merge($tags, $customTags);
    }

    // 去重并限制最多5个
    $tags = array_unique($tags);
    $tags = array_slice($tags, 0, 5);
    $tagsStr = implode(',', $tags);

    // 验证
    $errors = [];
    if (empty($title)) {
        $errors[] = '请输入话题标题';
    }
    if (mb_strlen($title) > 200) {
        $errors[] = '标题不能超过200个字符';
    }
    if (empty(trim(strip_tags($content)))) {
        $errors[] = '请输入话题内容';
    }

    // 敏感词检查（标题 + 内容）
    if (empty($errors)) {
        $titleCheck = containsSensitiveWord($title, [
            'scene' => '话题投稿',
            'page' => '/submit_discussion',
        ]);
        if ($titleCheck['found']) {
            $errors[] = '标题包含违规内容，请修改后重新提交';
        }
        $contentCheck = containsSensitiveWord(strip_tags($content), [
            'scene' => '话题投稿',
            'page' => '/submit_discussion',
        ]);
        if ($contentCheck['found']) {
            $errors[] = '内容包含违规内容，请修改后重新提交';
        }
    }

    if (empty($errors)) {
        if ($editId > 0 && $editDiscussion) {
            // 编辑模式：直接更新
            $stmt = $pdo->prepare("UPDATE discussions SET title = ?, content = ?, tags = ?, updated_at = NOW() WHERE id = ? AND user_id = ?");
            $result = $stmt->execute([$title, $content, $tagsStr, $editId, getCurrentUserId()]);

            if ($result) {
                setcookie('clear_markdown_draft', 'discussion_draft_edit_' . $editId, time() + 300, '/');
                sendMentionNotifications(getCurrentUserId(), $_SESSION['user_username'] ?? '', $content, '话题', $title, urlDiscussion($editId));
                header('Location: ' . urlDiscussion($editId));
                exit;
            } else {
                $message = '保存失败，请稍后重试';
                $messageType = 'error';
            }
        } else {
            // 新建模式
            $rateCheck = checkRateLimit(getCurrentUserId(), 'discussion');
            if (!$rateCheck['allowed']) {
                $message = '发布过于频繁，请等待 ' . $rateCheck['wait_seconds'] . ' 秒后再试';
                $messageType = 'error';
            } else {
                $stmt = $pdo->prepare("INSERT INTO discussions (user_id, title, content, tags, status) VALUES (?, ?, ?, ?, 'active')");
                $result = $stmt->execute([getCurrentUserId(), $title, $content, $tagsStr]);

                if ($result) {
                    $newId = $pdo->lastInsertId();
                    recordRateLimit(getCurrentUserId(), 'discussion');
                    setcookie('clear_markdown_draft', 'discussion_draft_new', time() + 300, '/');
                    sendMentionNotifications(getCurrentUserId(), $_SESSION['user_username'] ?? '', $content, '话题', $title, urlDiscussion($newId));
                    header('Location: ' . urlDiscussion($newId));
                    exit;
                } else {
                    $message = '发布失败，请稍后重试';
                    $messageType = 'error';
                }
            }
        }
    } else {
        $message = implode('；', $errors);
        $messageType = 'error';
    }
}

// 表单回显
$formTitle = $editDiscussion['title'] ?? ($_POST['title'] ?? '');
$formContent = $editDiscussion['content'] ?? ($_POST['content'] ?? '');
$formTagsRaw = $editDiscussion ? explode(',', (string)$editDiscussion['tags']) : ($_POST['tags'] ?? []);
$formTags = array_values(array_unique(array_filter(array_map('trim', $formTagsRaw))));
$extraTags = array_values(array_filter($formTags, function($tag) use ($defaultTags) {
    return !in_array($tag, $defaultTags, true);
}));
$pageTitle = $editId ? '编辑话题' : '发布话题';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo h($pageTitle); ?> - <?php echo h(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/user.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/markdown-editor.css?v=<?php echo ASSETS_VER; ?>">
    <?php renderSiteHead(); ?>
    <style>
        .tag-selector {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }
        .tag-chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 6px 14px;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-pill);
            color: var(--text-secondary);
            cursor: pointer;
            font-size: 0.85rem;
            transition: all var(--transition);
            user-select: none;
        }
        .tag-chip:hover {
            border-color: var(--accent-purple);
            color: var(--accent-purple);
        }
        .tag-chip.selected {
            background: rgba(167,139,250,0.15);
            border-color: var(--accent-purple);
            color: var(--accent-purple);
            font-weight: 600;
        }
        .tag-chip input[type="checkbox"] {
            display: none;
        }
        .custom-tag-input {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .custom-tag-input input {
            flex: 1;
        }
        .tag-count {
            font-size: 0.82rem;
            color: var(--text-muted);
            margin-top: 8px;
        }
        .tag-count .count-num {
            color: var(--accent-purple);
            font-weight: 600;
        }
    </style>
</head>
<body class="has-fixed-nav">
    <?php include 'includes/header.php'; ?>

    <main class="main">
        <div class="container" style="max-width: 900px;">
            <div class="card">
                <div class="card-header"><?php echo h($pageTitle); ?></div>
                <div class="card-body">
                    <?php if ($message): ?>
                        <div class="alert-<?php echo $messageType; ?>"><?php echo h($message); ?></div>
                    <?php endif; ?>

                    <form method="post" id="discussionForm">
                        <!-- 标题 -->
                        <div class="form-group">
                            <label class="form-label">话题标题 <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-input" placeholder="请输入话题标题" value="<?php echo h($formTitle); ?>" required maxlength="200">
                        </div>

                        <!-- 内容 (Markdown) -->
                        <div class="form-group">
                            <label class="form-label">话题内容 <span class="text-danger">*</span></label>
                            <div class="md-editor-wrap">
                                <div class="md-mode-tabs">
                                    <button type="button" class="md-mode-tab active" data-mode="edit">编写</button>
                                    <button type="button" class="md-mode-tab" data-mode="preview">预览</button>
                                    <button type="button" class="md-mode-tab" data-mode="split">分栏</button>
                                </div>
                                <div class="md-editor-toolbar">
                                    <button type="button" data-action="h1" title="一级标题">H1</button>
                                    <button type="button" data-action="h2" title="二级标题">H2</button>
                                    <button type="button" data-action="h3" title="三级标题">H3</button>
                                    <span class="md-toolbar-separator"></span>
                                    <button type="button" data-action="bold" title="加粗"><b>B</b></button>
                                    <button type="button" data-action="italic" title="斜体"><i>I</i></button>
                                    <button type="button" data-action="strikethrough" title="删除线"><s>S</s></button>
                                    <span class="md-toolbar-separator"></span>
                                    <button type="button" data-action="link" title="链接">&#128279;</button>
                                    <button type="button" data-action="mention" title="提及用户">@用户</button>
                                    <button type="button" data-action="image" title="插入图片">&#128247; 图片</button>
                                    <span class="md-toolbar-separator"></span>
                                    <button type="button" data-action="code" title="行内代码">`</button>
                                    <button type="button" data-action="codeblock" title="代码块">&lt;/&gt;</button>
                                    <span class="md-toolbar-separator"></span>
                                    <button type="button" data-action="ul" title="无序列表">&#8226; 列表</button>
                                    <button type="button" data-action="ol" title="有序列表">1. 列表</button>
                                    <button type="button" data-action="quote" title="引用">&gt; 引用</button>
                                    <span class="md-toolbar-separator"></span>
                                    <button type="button" data-action="hr" title="水平线">---</button>
                                    <button type="button" data-action="table" title="表格">表格</button>
                                    <button type="button" data-action="tsvtable" title="将文本快速转换为表格">文本转表</button>
                                </div>
                                <div class="md-editor-body mode-edit">
                                    <textarea id="md-editor" class="md-editor-textarea" placeholder="支持 Markdown 语法，输入内容后可切换预览查看效果..."><?php echo h($formContent); ?></textarea>
                                    <div id="md-preview" class="md-editor-preview-pane"></div>
                                </div>
                                <div class="md-editor-hint">支持 Markdown 语法 | <kbd>Tab</kbd> 缩进 | <kbd>Shift+Tab</kbd> 取消缩进</div>
                            </div>
                            <input type="hidden" name="content" id="contentInput">
                            <input type="file" id="md-image-uploader" accept="image/*" style="display:none;">
                        </div>

                        <!-- 标签 -->
                        <div class="form-group">
                            <label class="form-label">话题标签（最多5个）</label>
                            <div class="tag-selector" id="tagSelector">
                                <?php foreach ($defaultTags as $tag): ?>
                                    <label class="tag-chip <?php echo in_array($tag, $formTags) ? 'selected' : ''; ?>">
                                        <input type="checkbox" name="tags[]" value="<?php echo h($tag); ?>" <?php echo in_array($tag, $formTags) ? 'checked' : ''; ?>>
                                        <?php echo h($tag); ?>
                                    </label>
                                <?php endforeach; ?>
                                <?php foreach ($extraTags as $tag): ?>
                                    <label class="tag-chip selected" data-custom-tag="1">
                                        <input type="checkbox" name="tags[]" value="<?php echo h($tag); ?>" checked>
                                        <?php echo h($tag); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="custom-tag-input">
                                <input type="text" id="customTagInput" name="custom_tag" class="form-input" placeholder="输入自定义标签后按回车，或点击右侧按钮添加（支持多标签同时添加，逗号分隔）" value="">
                                <button type="button" class="btn btn-secondary" id="addCustomTagBtn">添加标签</button>
                            </div>
                            <div class="tag-count">已选择 <span class="count-num" id="tagCount"><?php echo count(array_filter($formTags)); ?></span>/5 个标签</div>
                        </div>

                        <div class="btn-group" style="margin-top: 24px;">
                            <button type="submit" class="btn"><?php echo $editId ? '保存修改' : '发布话题'; ?></button>
                            <a href="<?php echo $editId ? urlDiscussion($editId) : '/discussions'; ?>" class="btn btn-secondary"><?php echo $editId ? '返回话题' : '返回讨论区'; ?></a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <?php include 'includes/footer.php'; ?>

    <script src="/assets/js/marked.min.js"></script>
    <script>
    window.MARKDOWN_DRAFT_KEY = 'discussion_draft_<?php echo $editId ? 'edit_' . intval($editId) : 'new'; ?>';
    </script>
    <script src="/assets/js/markdown-editor.js?v=<?php echo ASSETS_VER; ?>"></script>
    <script src="/assets/js/markdown-draft.js?v=<?php echo ASSETS_VER; ?>"></script>
    <script>
    // 初始化 Markdown 编辑器
    MarkdownEditor.init({
        textareaId: 'md-editor',
        previewId: 'md-preview',
        imageUploadUrl: '/submit_discussion',
        draftKey: 'discussion_draft_<?php echo $editId ? 'edit_' . intval($editId) : 'new'; ?>'
    });

    // ========== 标签选择 ==========
    (function() {
        var tagSelector = document.getElementById('tagSelector');
        var tagCountEl = document.getElementById('tagCount');
        var customTagInput = document.getElementById('customTagInput');
        var addCustomTagBtn = document.getElementById('addCustomTagBtn');

        function getCheckedTags() {
            return Array.prototype.slice.call(tagSelector.querySelectorAll('input[type="checkbox"]:checked'));
        }

        function updateTagCount() {
            tagCountEl.textContent = getCheckedTags().length;
        }

        function syncChipSelectedState(checkbox) {
            var chip = checkbox.closest('.tag-chip');
            if (chip) {
                chip.classList.toggle('selected', checkbox.checked);
            }
        }

        function hasTagValue(tagText) {
            var value = tagText.trim();
            if (!value) return false;
            var allTags = tagSelector.querySelectorAll('input[type="checkbox"][name="tags[]"]');
            for (var i = 0; i < allTags.length; i++) {
                if ((allTags[i].value || '').trim() === value) {
                    return true;
                }
            }
            return false;
        }

        function createCustomTagChip(tagText) {
            var label = document.createElement('label');
            label.className = 'tag-chip selected';
            label.setAttribute('data-custom-tag', '1');

            var input = document.createElement('input');
            input.type = 'checkbox';
            input.name = 'tags[]';
            input.value = tagText;
            input.checked = true;

            label.appendChild(input);
            label.appendChild(document.createTextNode(tagText));
            tagSelector.appendChild(label);
        }

        function addCustomTagsFromInput() {
            var raw = (customTagInput.value || '').trim();
            if (!raw) return;

            var candidates = raw.split(/[，,]/).map(function(item) {
                return item.trim();
            }).filter(Boolean);

            if (!candidates.length) {
                customTagInput.value = '';
                return;
            }

            var checkedBefore = getCheckedTags().length;
            var addedCount = 0;

            for (var i = 0; i < candidates.length; i++) {
                var tag = candidates[i];
                if (hasTagValue(tag)) continue;
                if (checkedBefore + addedCount >= 5) break;
                createCustomTagChip(tag);
                addedCount++;
            }

            customTagInput.value = '';
            updateTagCount();

            if (addedCount === 0 && checkedBefore >= 5) {
                alert('最多选择5个标签');
            }
        }

        tagSelector.addEventListener('change', function(e) {
            if (e.target.type !== 'checkbox') return;

            var checkbox = e.target;
            var checked = getCheckedTags();
            if (checked.length > 5) {
                checkbox.checked = false;
                alert('最多选择5个标签');
                return;
            }

            syncChipSelectedState(checkbox);
            updateTagCount();
        });

        addCustomTagBtn.addEventListener('click', function() {
            addCustomTagsFromInput();
        });

        customTagInput.addEventListener('keydown', function(e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            addCustomTagsFromInput();
        });

        updateTagCount();
    })();

    // ========== 提交前处理 ==========
    document.getElementById('discussionForm').addEventListener('submit', function(e) {
        var textarea = document.getElementById('md-editor');
        document.getElementById('contentInput').value = textarea.value;

        if (!confirm(<?php echo json_encode($editId ? '确认提交本次修改吗？提交后将立即更新内容。' : '确认发布新话题吗？发布后将立即公开。'); ?>)) {
            e.preventDefault();
            return;
        }

        if (!textarea.value.trim()) {
            e.preventDefault();
            alert('请输入话题内容');
            return;
        }

        // 检查标签总数
        var checked = document.querySelectorAll('#tagSelector input[type="checkbox"]:checked').length;
        var customTag = document.querySelector('input[name="custom_tag"]').value.trim();
        var customCount = customTag ? customTag.split(',').filter(function(t){ return t.trim(); }).length : 0;
        if (checked + customCount > 5) {
            e.preventDefault();
            alert('标签总数不能超过5个');
            return;
        }
    });
    </script>
</body>
</html>
