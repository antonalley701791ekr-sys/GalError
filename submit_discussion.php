<?php
require_once 'includes/user_auth.php';
require_once 'includes/markdown.php';
require_once 'includes/sensitive_filter.php';
require_once 'includes/view.php';

requireUserLogin();

if (checkUserBanned()) {
    header('Location: /');
    exit;
}

$pdo = getDB();
$message = '';
$messageType = '';
$defaultTags = ['求助', '讨论', '分享', '报错问题', '汉化问题', '经验交流', '闲聊', '建议'];

$editId = intval($_GET['edit'] ?? 0);
$editDiscussion = null;
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM discussions WHERE id = ? AND status = 'active'");
    $stmt->execute([$editId]);
    $editDiscussion = $stmt->fetch();
    if (!$editDiscussion) {
        $editId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_image') {
    header('Content-Type: application/json');
    if (!isset($_FILES['image'])) {
        echo json_encode(['success' => false, 'message' => '未选择文件']);
        exit;
    }
    echo json_encode(handleArticleImageUpload($_FILES['image']));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    if (!csrf_validate_request('submit_discussion_form')) {
        $message = '请求已过期，请刷新后重试';
        $messageType = 'error';
    } else {
        $title = trim($_POST['title'] ?? '');
        $content = $_POST['content'] ?? '';
        $tags = $_POST['tags'] ?? [];
        $customTag = trim($_POST['custom_tag'] ?? '');

        if ($customTag) {
            $customTags = array_filter(array_map('trim', explode(',', $customTag)));
            $tags = array_merge($tags, $customTags);
        }

        $tags = array_slice(array_unique($tags), 0, 5);
        $tagsStr = implode(',', $tags);

        $errors = [];
        if ($title === '') {
            $errors[] = '请输入话题标题';
        }
        if (mb_strlen($title) > 200) {
            $errors[] = '标题不能超过200个字符';
        }
        if (trim(strip_tags($content)) === '') {
            $errors[] = '请输入话题内容';
        }

        if (empty($errors)) {
            $titleCheck = containsSensitiveWord($title, ['scene' => '话题投稿', 'page' => '/submit_discussion']);
            if ($titleCheck['found']) {
                $errors[] = '标题包含违规内容，请修改后重新提交';
            }
            $contentCheck = containsSensitiveWord(strip_tags($content), ['scene' => '话题投稿', 'page' => '/submit_discussion']);
            if ($contentCheck['found']) {
                $errors[] = '内容包含违规内容，请修改后重新提交';
            }
        }

        if (empty($errors)) {
            if ($editId > 0 && $editDiscussion) {
                $currentUserId = (int)getCurrentUserId();
                $isAdminEditor = isAdmin();
                if (!$isAdminEditor && (int)($editDiscussion['user_id'] ?? 0) !== $currentUserId) {
                    $message = '您没有权限编辑这个话题';
                    $messageType = 'error';
                } else {
                    $stmt = $pdo->prepare("UPDATE discussions SET title = ?, content = ?, tags = ?, updated_at = NOW() WHERE id = ?");
                    $result = $stmt->execute([$title, $content, $tagsStr, $editId]);

                    if ($result) {
                        setcookie('clear_markdown_draft', 'discussion_draft_edit_' . $editId, time() + 300, '/');
                        sendMentionNotifications(getCurrentUserId(), $_SESSION['user_username'] ?? '', $content, '话题', $title, urlDiscussion($editId));
                        header('Location: ' . urlDiscussion($editId));
                        exit;
                    }
                    $message = '保存失败，请稍后重试';
                    $messageType = 'error';
                }
            } else {
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
                    }
                    $message = '发布失败，请稍后重试';
                    $messageType = 'error';
                }
            }
        } else {
            $message = implode('；', $errors);
            $messageType = 'error';
        }
    }
}

$formTitle = $editDiscussion['title'] ?? ($_POST['title'] ?? '');
$formContent = $editDiscussion['content'] ?? ($_POST['content'] ?? '');
$formTagsRaw = $editDiscussion ? explode(',', (string)$editDiscussion['tags']) : ($_POST['tags'] ?? []);
$formTags = array_values(array_unique(array_filter(array_map('trim', $formTagsRaw))));
$extraTags = array_values(array_filter($formTags, function($tag) use ($defaultTags) {
    return !in_array($tag, $defaultTags, true);
}));
$pageTitle = $editId ? '编辑话题' : '发布话题';
$draftKey = 'discussion_draft_' . ($editId ? 'edit_' . intval($editId) : 'new');
$allowDraftRestore = $editId > 0 ? false : true;

ob_start(); renderSiteHead(); $siteHeadHtml = ob_get_clean();
ob_start(); include __DIR__ . '/includes/header.php'; $headerHtml = ob_get_clean();
ob_start(); include __DIR__ . '/includes/footer.php'; $footerHtml = ob_get_clean();

ob_start();
?>
<style>
.tag-selector { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
.tag-chip { display: inline-flex; align-items: center; gap: 4px; padding: 6px 14px; background: var(--glass-bg); border: 1px solid var(--glass-border); border-radius: var(--radius-pill); color: var(--text-secondary); cursor: pointer; font-size: 0.85rem; transition: all var(--transition); user-select: none; }
.tag-chip:hover { border-color: var(--accent-purple); color: var(--accent-purple); }
.tag-chip.selected { background: rgba(167,139,250,0.15); border-color: var(--accent-purple); color: var(--accent-purple); font-weight: 600; }
.tag-chip input[type="checkbox"] { display: none; }
.custom-tag-input { display: flex; gap: 8px; align-items: center; }
.custom-tag-input input { flex: 1; }
.tag-count { font-size: 0.82rem; color: var(--text-muted); margin-top: 8px; }
.tag-count .count-num { color: var(--accent-purple); font-weight: 600; }
</style>
<?php
$inlineStyleHtml = ob_get_clean();

ob_start();
?>
<script src="/assets/js/marked.min.js"></script>
<script>window.MARKDOWN_DRAFT_KEY = <?php echo json_encode($draftKey); ?>;
window.MARKDOWN_DRAFT_ALLOW_RESTORE = <?php echo $allowDraftRestore ? 'true' : 'false'; ?>;</script>
<script src="/assets/js/markdown-editor.js?v=<?php echo ASSETS_VER; ?>"></script>
<script src="/assets/js/markdown-draft.js?v=<?php echo ASSETS_VER; ?>"></script>
<script>
MarkdownEditor.init({ textareaId: 'md-editor', previewId: 'md-preview', imageUploadUrl: '/submit_discussion', draftKey: <?php echo json_encode($draftKey); ?> });
(function() {
    var tagSelector = document.getElementById('tagSelector');
    var tagCountEl = document.getElementById('tagCount');
    var customTagInput = document.getElementById('customTagInput');
    var addCustomTagBtn = document.getElementById('addCustomTagBtn');
    function getCheckedTags() { return Array.prototype.slice.call(tagSelector.querySelectorAll('input[type="checkbox"]:checked')); }
    function updateTagCount() { tagCountEl.textContent = getCheckedTags().length; }
    function syncChipSelectedState(checkbox) { var chip = checkbox.closest('.tag-chip'); if (chip) chip.classList.toggle('selected', checkbox.checked); }
    function hasTagValue(tagText) {
        var value = tagText.trim();
        if (!value) return false;
        var allTags = tagSelector.querySelectorAll('input[type="checkbox"][name="tags[]"]');
        for (var i = 0; i < allTags.length; i++) if ((allTags[i].value || '').trim() === value) return true;
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
        var candidates = raw.split(/[，,]/).map(function(item) { return item.trim(); }).filter(Boolean);
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
        if (addedCount === 0 && checkedBefore >= 5) alert('最多选择5个标签');
    }
    tagSelector.addEventListener('change', function(e) {
        if (e.target.type !== 'checkbox') return;
        var checkbox = e.target;
        if (getCheckedTags().length > 5) {
            checkbox.checked = false;
            alert('最多选择5个标签');
            return;
        }
        syncChipSelectedState(checkbox);
        updateTagCount();
    });
    addCustomTagBtn.addEventListener('click', addCustomTagsFromInput);
    customTagInput.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); addCustomTagsFromInput(); } });
    updateTagCount();
})();
document.getElementById('discussionForm').addEventListener('submit', function(e) {
    var textarea = document.getElementById('md-editor');
    document.getElementById('contentInput').value = textarea.value;
    if (!confirm(<?php echo json_encode($editId ? '确认提交本次修改吗？提交后将立即更新内容。' : '确认发布新话题吗？发布后将立即公开。'); ?>)) { e.preventDefault(); return; }
    if (!textarea.value.trim()) { e.preventDefault(); alert('请输入话题内容'); return; }
    var checked = document.querySelectorAll('#tagSelector input[type="checkbox"]:checked').length;
    var customTag = document.querySelector('input[name="custom_tag"]').value.trim();
    var customCount = customTag ? customTag.split(',').filter(function(t){ return t.trim(); }).length : 0;
    if (checked + customCount > 5) { e.preventDefault(); alert('标签总数不能超过5个'); }
});
</script>
<?php
$pageScriptsHtml = ob_get_clean();

view('front/submit_discussion.twig', [
    'page_title' => $pageTitle,
    'message' => $message,
    'message_type' => $messageType,
    'form_title' => $formTitle,
    'form_content' => $formContent,
    'form_tags' => $formTags,
    'default_tags' => $defaultTags,
    'extra_tags' => $extraTags,
    'submit_text' => $editId ? '保存修改' : '发布话题',
    'back_url' => $editId ? urlDiscussion($editId) : '/discussions',
    'back_text' => $editId ? '返回话题' : '返回讨论区',
    'csrf_html' => csrf_input('submit_discussion_form'),
    'site_head_html' => $siteHeadHtml,
    'header_html' => $headerHtml,
    'footer_html' => $footerHtml,
    'inline_style_html' => $inlineStyleHtml,
    'page_scripts_html' => $pageScriptsHtml,
]);
