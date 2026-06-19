<?php
require_once 'includes/user_auth.php';
require_once 'includes/sanitizer.php';
require_once 'includes/sensitive_filter.php';
require_once 'includes/view.php';

requireUserLogin();

$pdo = getDB();
$isAdminUser = canCurrentUserBypassModeration();

if (isset($_GET['action']) && $_GET['action'] === 'search_vndb') {
    header('Content-Type: application/json; charset=utf-8');
    $vndbId = trim($_POST['vndb_id'] ?? '');
    if ($vndbId === '') {
        echo json_encode(['success' => false, 'message' => '请输入 VNDB ID']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT id, title FROM games WHERE vndb_id = ? AND status = 'approved'");
    $stmt->execute([$vndbId]);
    $existingGame = $stmt->fetch();
    if ($existingGame) {
        echo json_encode(['success' => true, 'game_id' => $existingGame['id'], 'message' => '已找到游戏：' . $existingGame['title']]);
        exit;
    }
    $stmt = $pdo->prepare("SELECT id FROM games WHERE vndb_id = ? AND status = 'pending'");
    $stmt->execute([$vndbId]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => '该游戏正在等待审核，审核通过后可提交报错']);
    } else {
        echo json_encode(['success' => false, 'message' => '游戏未收录，请先提交游戏', 'show_submit_link' => true]);
    }
    exit;
}

$message = '';
$messageType = '';
$systemCategoryOptions = [
    'windows' => 'Windows',
    'android_emulator' => '安卓模拟器',
    'console_handheld' => '主机掌机',
    'mobile_native' => '手机原生',
    'win_handheld' => 'Win掌机',
    'cloud_streaming' => '云/串流',
    'other' => '其他',
];
$systemCategoryPlaceholders = [
    'windows' => '例如：Windows10 64位 / Windows11 专业版',
    'android_emulator' => '例如：Winlator 5.0 / Mobox 模拟器 / ExaGear ED302 / KRKR2 安卓版',
    'console_handheld' => '例如：Switch 大气层破解 / PSV 3.65 / PS4 折腾版 / PSP 6.61',
    'mobile_native' => '例如：安卓13 原生直装 / iOS16 官方移植版',
    'win_handheld' => '例如：奥丁2 Windows版 / ROG Ally / AYA Neo 掌机',
    'cloud_streaming' => '例如：Parsec串流 / 云电脑Win10 / ToDesk远程游玩',
    'other' => '请自行填写小众游玩环境、其他系统设备',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate_request('submit_error_form')) {
        $message = '请求已过期，请刷新后重试';
        $messageType = 'error';
    } elseif (isUserBanned()) {
        $message = '您的账户已被封禁，无法提交内容';
        $messageType = 'error';
    } else {
        $gameId = intval($_POST['game_id'] ?? 0);
        $categoryId = intval($_POST['category_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $phenomenon = trim($_POST['phenomenon'] ?? '');
        $engineInfo = trim($_POST['engine_info'] ?? '');
        $systemCategory = trim($_POST['system_category'] ?? '');
        $systemInfo = trim($_POST['system_info'] ?? '');
        $androidCpu = trim($_POST['android_cpu'] ?? '');
        $androidModel = trim($_POST['android_model'] ?? '');
        $androidVersion = trim($_POST['android_version'] ?? '');
        $patchInfo = trim($_POST['patch_info'] ?? '');
        $solution = trim($_POST['solution'] ?? '');
        $hasSolution = ($_POST['has_solution'] ?? 'no') === 'yes';
        if (!$hasSolution) $solution = '';
        if (!array_key_exists($systemCategory, $systemCategoryOptions)) $systemCategory = 'other';
        if ($systemCategory !== 'android_emulator') {
            $androidCpu = '';
            $androidModel = '';
            $androidVersion = '';
        }
        if (!$gameId || !$categoryId || !$title || $phenomenon === '' || $systemInfo === '') {
            $message = '请填写所有必填字段';
            $messageType = 'error';
        } elseif ($systemCategory === 'android_emulator' && ($androidCpu === '' || $androidModel === '' || $androidVersion === '')) {
            $message = '安卓模拟器分类下请填写手机处理器、手机机型和安卓版本';
            $messageType = 'error';
        } elseif ($hasSolution && $solution === '') {
            $message = '选择了"有解决方案"但未填写解决方案内容';
            $messageType = 'error';
        } else {
            $sensitiveFields = ['标题' => $title, '错误现象' => $phenomenon, '游戏引擎信息' => $engineInfo, '系统信息' => $systemInfo, '汉化补丁' => $patchInfo];
            if ($hasSolution) $sensitiveFields['解决方案'] = $solution;
            foreach ($sensitiveFields as $label => $value) {
                if ($value === '') continue;
                $check = containsSensitiveWord($value, ['scene' => '报错投稿', 'page' => '/submit']);
                if ($check['found']) {
                    $message = $label . '包含违规内容，请修改后重新提交';
                    $messageType = 'error';
                    break;
                }
            }
        }
        if ($messageType !== 'error') {
            $screenshots = [];
            if (isset($_FILES['screenshots']) && is_array($_FILES['screenshots']['name'])) {
                foreach ($_FILES['screenshots']['name'] as $key => $name) {
                    if ($_FILES['screenshots']['error'][$key] === UPLOAD_ERR_OK && $name) {
                        $upload = handleFileUpload(['name' => $name, 'type' => $_FILES['screenshots']['type'][$key], 'tmp_name' => $_FILES['screenshots']['tmp_name'][$key], 'error' => $_FILES['screenshots']['error'][$key], 'size' => $_FILES['screenshots']['size'][$key]]);
                        if ($upload['success']) $screenshots[] = $upload['filename'];
                    }
                }
            }
            $solutionScreenshots = [];
            if ($hasSolution && isset($_FILES['solution_screenshots']) && is_array($_FILES['solution_screenshots']['name'])) {
                foreach ($_FILES['solution_screenshots']['name'] as $key => $name) {
                    if ($_FILES['solution_screenshots']['error'][$key] === UPLOAD_ERR_OK && $name) {
                        $upload = handleFileUpload(['name' => $name, 'type' => $_FILES['solution_screenshots']['type'][$key], 'tmp_name' => $_FILES['solution_screenshots']['tmp_name'][$key], 'error' => $_FILES['solution_screenshots']['error'][$key], 'size' => $_FILES['solution_screenshots']['size'][$key]]);
                        if ($upload['success']) $solutionScreenshots[] = $upload['filename'];
                    }
                }
            }
            $submitStatus = getCurrentUserModerationStatus();
            $currentUserId = getCurrentUserId();
            $pdo->beginTransaction();
            try {
                $solutionScreenshotsCsv = implode(',', array_values(array_filter(array_map('trim', $solutionScreenshots))));
                $stmt = $pdo->prepare("INSERT INTO errors (game_id, category_id, title, phenomenon, engine_info, system_category, system_info, android_cpu, android_model, android_version, patch_info, screenshots, solution_screenshots, user_ip, user_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $result = $stmt->execute([$gameId, $categoryId, $title, $phenomenon, $engineInfo, $systemCategory, $systemInfo, $androidCpu ?: null, $androidModel ?: null, $androidVersion ?: null, $patchInfo, implode(',', $screenshots), $solutionScreenshotsCsv, getClientIP(), $currentUserId, $submitStatus]);
                if ($result && $hasSolution) {
                    $errorId = (int)$pdo->lastInsertId();
                    $solStmt = $pdo->prepare("INSERT INTO error_solutions (error_id, user_id, solution, solution_screenshots, status, is_primary) VALUES (?, ?, ?, ?, ?, 1)");
                    $solStmt->execute([$errorId, $currentUserId, $solution, $solutionScreenshotsCsv, $submitStatus]);
                }
                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $result = false;
            }
            if ($result) {
                $message = $isAdminUser ? '提交成功！报错已直接发布。' : '提交成功！您的报错将在管理员审核后显示。';
                $messageType = 'success';
                $_POST = [];
            } else {
                $message = '提交失败，请重试';
                $messageType = 'error';
            }
        }
    }
}

$games = $pdo->query("SELECT * FROM games WHERE status = 'approved' ORDER BY title ASC")->fetchAll();
$categories = $pdo->query("SELECT * FROM error_categories ORDER BY sort_order ASC")->fetchAll();
$preselectedGameId = intval($_GET['game_id'] ?? 0);

ob_start(); renderSiteHead(); $siteHeadHtml = ob_get_clean();
ob_start(); include __DIR__ . '/includes/header.php'; $headerHtml = ob_get_clean();
ob_start(); renderAnnouncement(); $announcementHtml = ob_get_clean();
ob_start(); include __DIR__ . '/includes/footer.php'; $footerHtml = ob_get_clean();

$form = [
    'category_id' => intval($_POST['category_id'] ?? 0),
    'title' => $_POST['title'] ?? '',
    'phenomenon' => $_POST['phenomenon'] ?? '',
    'engine_info' => $_POST['engine_info'] ?? '',
    'system_category' => $_POST['system_category'] ?? 'windows',
    'system_info' => $_POST['system_info'] ?? '',
    'android_cpu' => $_POST['android_cpu'] ?? '',
    'android_model' => $_POST['android_model'] ?? '',
    'android_version' => $_POST['android_version'] ?? '',
    'patch_info' => $_POST['patch_info'] ?? '',
    'has_solution' => $_POST['has_solution'] ?? 'no',
    'solution' => $_POST['solution'] ?? '',
];
$selectedGameId = intval($_POST['game_id'] ?? $preselectedGameId);

ob_start();
?>
<script>
function toggleSolution() {
    var hasSolution = document.querySelector('input[name="has_solution"]:checked').value === 'yes';
    var textarea = document.getElementById('solution_textarea');
    var solutionScreenshotsGroup = document.getElementById('solution_screenshots_group');
    textarea.disabled = !hasSolution;
    if (!hasSolution) textarea.value = '';
    solutionScreenshotsGroup.style.display = hasSolution ? '' : 'none';
}
function searchGameByVndb() {
    var vndbInput = document.getElementById('vndb_id_input');
    var btn = document.getElementById('vndbSearchBtn');
    var resultDiv = document.getElementById('vndbSearchResult');
    var gameSelect = document.getElementById('game_select');
    var vndbId = vndbInput.value.trim();
    if (!vndbId) { resultDiv.innerHTML = '<div class="alert-error" style="margin-top: 10px;">请输入 VNDB ID</div>'; return; }
    btn.disabled = true; btn.textContent = '查询中...'; resultDiv.innerHTML = '';
    var formData = new FormData(); formData.append('vndb_id', vndbId);
    fetch('/submit?action=search_vndb', { method: 'POST', body: formData }).then(function(res) { return res.json(); }).then(function(data) {
        if (data.success && data.game_id) {
            var found = false;
            for (var i = 0; i < gameSelect.options.length; i++) { if (gameSelect.options[i].value == data.game_id) { gameSelect.selectedIndex = i; found = true; break; } }
            resultDiv.innerHTML = '<div class="alert-success" style="margin-top: 10px;">' + escapeHtml(data.message) + (found ? '' : '（下拉框中未找到）') + '</div>';
        } else {
            var html = '<div class="alert-error" style="margin-top: 10px;">' + escapeHtml(data.message);
            if (data.show_submit_link) html += '<br><a href="/submit_game">去提交游戏</a>';
            resultDiv.innerHTML = html + '</div>';
        }
    }).catch(function() { resultDiv.innerHTML = '<div class="alert-error" style="margin-top: 10px;">查询失败，请重试</div>'; }).finally(function() { btn.disabled = false; btn.textContent = '查询游戏'; });
}
function escapeHtml(text) { var div = document.createElement('div'); div.textContent = text; return div.innerHTML; }
(function() {
    var categorySelect = document.getElementById('system_category_select');
    var systemInput = document.getElementById('system_info_input');
    var androidExtra = document.getElementById('android-extra-fields');
    if (!categorySelect || !systemInput) return;
    var placeholders = <?php echo json_encode($systemCategoryPlaceholders, JSON_UNESCAPED_UNICODE); ?>;
    function updateSystemPlaceholder() {
        var key = categorySelect.value || 'other';
        systemInput.placeholder = placeholders[key] || placeholders.other || '请填写系统信息';
        if (androidExtra) {
            var isAndroid = key === 'android_emulator';
            androidExtra.style.display = isAndroid ? '' : 'none';
            ['android_cpu_input', 'android_model_input', 'android_version_input'].forEach(function(id) { var el = document.getElementById(id); if (!el) return; el.required = isAndroid; if (!isAndroid) el.value = ''; });
        }
    }
    categorySelect.addEventListener('change', updateSystemPlaceholder);
    updateSystemPlaceholder();
})();
document.getElementById('vndb_id_input').addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); searchGameByVndb(); } });
</script>
<?php
$pageScriptsHtml = ob_get_clean();

view('front/submit_error.twig', [
    'message' => $message,
    'message_type' => $messageType,
    'games' => $games,
    'categories' => $categories,
    'system_category_options' => $systemCategoryOptions,
    'form' => $form,
    'selected_game_id' => $selectedGameId,
    'csrf_html' => csrf_input('submit_error_form'),
    'site_head_html' => $siteHeadHtml,
    'header_html' => $headerHtml,
    'announcement_html' => $announcementHtml,
    'footer_html' => $footerHtml,
    'page_scripts_html' => $pageScriptsHtml,
]);
