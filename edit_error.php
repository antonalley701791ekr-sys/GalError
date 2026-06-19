<?php
require_once 'includes/user_auth.php';
require_once 'includes/auth.php';
require_once 'includes/sanitizer.php';
require_once 'includes/view.php';

$fromAdmin = isset($_GET['from_admin']) && $_GET['from_admin'] === '1';
if ($fromAdmin) {
    checkLogin();
    requirePermission('errors', 'edit');
} else {
    requireUserLogin();
}

$pdo = getDB();
$isAdminUser = canCurrentUserBypassModeration() || $fromAdmin;

$errorId = intval($_GET['id'] ?? 0);
if (!$errorId) {
    header('Location: ' . ($fromAdmin ? '/admin/errors.php' : '/'));
    exit;
}

// 获取报错信息（前台仅已通过审核；后台入口可编辑任意状态）
if ($fromAdmin) {
    $stmt = $pdo->prepare("
        SELECT e.*, g.title as game_title, g.vndb_id, c.name as category_name 
        FROM errors e 
        JOIN games g ON e.game_id = g.id 
        JOIN error_categories c ON e.category_id = c.id 
        WHERE e.id = ?
    ");
} else {
    $stmt = $pdo->prepare("
        SELECT e.*, g.title as game_title, g.vndb_id, c.name as category_name 
        FROM errors e 
        JOIN games g ON e.game_id = g.id 
        JOIN error_categories c ON e.category_id = c.id 
        WHERE e.id = ? AND e.status = 'approved'
    ");
}
$stmt->execute([$errorId]);
$error = $stmt->fetch();

if (!$error) {
    header('Location: /');
    exit;
}

// 获取报错分类
$categories = $pdo->query("SELECT * FROM error_categories ORDER BY sort_order ASC")->fetchAll();

// 获取已审核游戏列表
$games = $pdo->query("SELECT * FROM games WHERE status = 'approved' ORDER BY title ASC")->fetchAll();

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

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfScope = $fromAdmin ? 'admin_form' : 'edit_error_form';
    if (!csrf_validate_request($csrfScope)) {
        $message = '请求已过期，请刷新后重试';
        $messageType = 'error';
    } elseif (!$fromAdmin && isUserBanned()) {
        $message = '您的账户已被封禁，无法修改内容';
        $messageType = 'error';
    } else {
    $title = trim($_POST['title'] ?? '');
    $categoryId = intval($_POST['category_id'] ?? 0);
    $phenomenon = trim($_POST['phenomenon'] ?? '');
    $engineInfo = trim($_POST['engine_info'] ?? '');
    $systemCategory = trim($_POST['system_category'] ?? '');
    $systemInfo = trim($_POST['system_info'] ?? '');
    $androidCpu = trim($_POST['android_cpu'] ?? '');
    $androidModel = trim($_POST['android_model'] ?? '');
    $androidVersion = trim($_POST['android_version'] ?? '');
    $patchInfo = trim($_POST['patch_info'] ?? '');

    if (!array_key_exists($systemCategory, $systemCategoryOptions)) {
        $systemCategory = 'other';
    }

    if ($systemCategory !== 'android_emulator') {
        $androidCpu = '';
        $androidModel = '';
        $androidVersion = '';
    }

    if (!$title || !$categoryId || empty($phenomenon) || empty($systemInfo)) {
        $message = '请填写所有必填字段';
        $messageType = 'error';
    } elseif ($systemCategory === 'android_emulator' && (empty($androidCpu) || empty($androidModel) || empty($androidVersion))) {
        $message = '安卓模拟器分类下请填写手机处理器、手机机型和安卓版本';
        $messageType = 'error';
    } else {
        // 构建旧数据快照
        $oldData = json_encode([
            'title' => $error['title'],
            'category_id' => $error['category_id'],
            'category_name' => $error['category_name'] ?? '',
            'phenomenon' => $error['phenomenon'],
            'engine_info' => $error['engine_info'],
            'system_category' => $error['system_category'] ?? null,
            'system_info' => $error['system_info'],
            'android_cpu' => $error['android_cpu'] ?? '',
            'android_model' => $error['android_model'] ?? '',
            'android_version' => $error['android_version'] ?? '',
            'patch_info' => $error['patch_info'],
        ], JSON_UNESCAPED_UNICODE);

        // 构建新数据快照
        $newData = json_encode([
            'title' => $title,
            'category_id' => $categoryId,
            'category_name' => '',
            'phenomenon' => $phenomenon,
            'engine_info' => $engineInfo,
            'system_category' => $systemCategory,
            'system_info' => $systemInfo,
            'android_cpu' => $androidCpu,
            'android_model' => $androidModel,
            'android_version' => $androidVersion,
            'patch_info' => $patchInfo,
        ], JSON_UNESCAPED_UNICODE);

        // 处理报错截图：保留的旧截图
        $oldScreenshots = $error['screenshots'] ? array_filter(array_map('trim', explode(',', $error['screenshots']))) : [];
        $keepScreenshots = $_POST['keep_screenshots'] ?? [];
        $keepScreenshots = array_filter($keepScreenshots, function($s) use ($oldScreenshots) {
            return in_array(basename($s), $oldScreenshots);
        });
        $keepScreenshots = array_map('basename', $keepScreenshots);

        // 处理新上传的报错截图
        $newUploadedScreenshots = [];
        if (isset($_FILES['screenshots']) && is_array($_FILES['screenshots']['name'])) {
            foreach ($_FILES['screenshots']['name'] as $key => $name) {
                if ($_FILES['screenshots']['error'][$key] === UPLOAD_ERR_OK && $name) {
                    $file = [
                        'name' => $name,
                        'type' => $_FILES['screenshots']['type'][$key],
                        'tmp_name' => $_FILES['screenshots']['tmp_name'][$key],
                        'error' => $_FILES['screenshots']['error'][$key],
                        'size' => $_FILES['screenshots']['size'][$key]
                    ];
                    $upload = handleFileUpload($file);
                    if ($upload['success']) {
                        $newUploadedScreenshots[] = $upload['filename'];
                    }
                }
            }
        }

        $allNewScreenshots = array_merge($keepScreenshots, $newUploadedScreenshots);

        // 检查是否真的有修改（文本字段或截图变更都算修改）
        $oldDecoded = json_decode($oldData, true);
        $newDecoded = json_decode($newData, true);
        $hasTextChange = ($oldDecoded != $newDecoded);
        $categoryNameMap = [];
        foreach ($categories as $categoryRow) {
            $categoryNameMap[(string)$categoryRow['id']] = (string)$categoryRow['name'];
        }
        $oldCategoryName = $categoryNameMap[(string)($error['category_id'] ?? '')] ?? (string)($error['category_name'] ?? '');
        $newCategoryName = $categoryNameMap[(string)$categoryId] ?? '';
        $currentScreenshots = array_values(array_filter(array_map('trim', explode(',', (string)($error['screenshots'] ?? '')))));
        $hasScreenshotChange = ($currentScreenshots !== $allNewScreenshots);
        $categoryOnlyChange = $hasTextChange
            && (count(array_diff_assoc($oldDecoded, $newDecoded)) === 1)
            && (($oldDecoded['category_id'] ?? null) != ($newDecoded['category_id'] ?? null))
            && $oldCategoryName !== $newCategoryName;

        if (!$hasTextChange && !$hasScreenshotChange) {
            $message = '未检测到任何修改';
            $messageType = 'error';
        } else {
            $currentUserId = getCurrentUserId();
            $pdo->beginTransaction();
            try {
                if ($isAdminUser) {
                    $stmt = $pdo->prepare("INSERT INTO error_revisions (error_id, user_id, user_ip, old_data, old_engine_info, new_data, new_engine_info, old_screenshots, new_screenshots, old_solution_screenshots, new_solution_screenshots, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', NOW())");
                    $revResult = $stmt->execute([
                        $errorId,
                        $currentUserId,
                        getClientIP(),
                        $oldData,
                        $error['engine_info'] ?? '',
                        $newData,
                        $engineInfo,
                        implode(',', $oldScreenshots),
                        implode(',', $allNewScreenshots),
                        '',
                        ''
                    ]);

                    if ($revResult) {
                        $stmt = $pdo->prepare("UPDATE errors SET title=?, category_id=?, phenomenon=?, engine_info=?, system_category=?, system_info=?, android_cpu=?, android_model=?, android_version=?, patch_info=?, screenshots=?, solution_screenshots=? WHERE id=?");
                        $result = $stmt->execute([
                            $title,
                            $categoryId,
                            $phenomenon,
                            $engineInfo,
                            $systemCategory,
                            $systemInfo,
                            $androidCpu ?: null,
                            $androidModel ?: null,
                            $androidVersion ?: null,
                            $patchInfo,
                            implode(',', $allNewScreenshots),
                            '',
                            $errorId
                        ]);

                        if ($result) {
                            $categoryNameStmt = $pdo->prepare("SELECT name FROM error_categories WHERE id = ?");
                            $categoryNameStmt->execute([$categoryId]);
                            $newCategoryName = (string)($categoryNameStmt->fetchColumn() ?: '');
                            $newDataDecoded = json_decode($newData, true) ?: [];
                            $newDataDecoded['category_name'] = $newCategoryName;
                            $newData = json_encode($newDataDecoded, JSON_UNESCAPED_UNICODE);

                            $updateRevStmt = $pdo->prepare("UPDATE error_revisions SET new_data = ? WHERE error_id = ? AND status = 'approved' ORDER BY id DESC LIMIT 1");
                            $updateRevStmt->execute([$newData, $errorId]);
                        }
                    } else {
                        $result = false;
                    }
                } else {
                    // 插入修改记录
                    $stmt = $pdo->prepare("
                        INSERT INTO error_revisions (error_id, user_id, user_ip, old_data, old_engine_info, new_data, new_engine_info, old_screenshots, new_screenshots, old_solution_screenshots, new_solution_screenshots, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                    ");
                    $result = $stmt->execute([
                        $errorId,
                        $currentUserId,
                        getClientIP(),
                        $oldData,
                        $error['engine_info'] ?? '',
                        $newData,
                        $engineInfo,
                        implode(',', $oldScreenshots),
                        implode(',', $allNewScreenshots),
                        '',
                        ''
                    ]);
                }

                if ($result) {
                    $pdo->commit();

                    $message = $isAdminUser ? '修改已直接生效' : '修改已提交，等待管理员审核';
                    $messageType = 'success';

                    $stmt = $pdo->prepare("
                        SELECT e.*, g.title as game_title, g.vndb_id, c.name as category_name 
                        FROM errors e 
                        JOIN games g ON e.game_id = g.id 
                        JOIN error_categories c ON e.category_id = c.id 
                        WHERE e.id = ?
                    ");
                    $stmt->execute([$errorId]);
                    $error = $stmt->fetch();
                    $currentScreenshots = $error['screenshots'] ? array_filter(array_map('trim', explode(',', $error['screenshots']))) : [];
                } else {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $message = '提交失败，请重试';
                    $messageType = 'error';
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = '提交失败，请重试';
                $messageType = 'error';
            }
        }
    }
}
}

// 当前报错截图列表
$currentScreenshots = $error['screenshots'] ? array_filter(array_map('trim', explode(',', $error['screenshots']))) : [];
ob_start();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>编辑报错 - <?php echo h(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/user.css?v=<?php echo ASSETS_VER; ?>">
    <?php renderSiteHead(); ?>
</head>
<body class="has-fixed-nav">
    <?php include __DIR__ . '/includes/header.php'; ?>

    <?php renderAnnouncement(); ?>

    <!-- 主要内容 -->
    <main class="main">
        <div class="container">
            <div class="card">
                <div class="card-header">编辑报错 - <?php echo hs($error['game_title']); ?></div>
                <div class="card-body">
                    <?php if ($message): ?>
                        <div class="alert-<?php echo $messageType; ?>">
                            <?php echo h($message); ?>
                            <?php if ($messageType === 'success'): ?>
                                <br><?php if ($fromAdmin): ?><a href="/admin/error_detail.php?id=<?php echo (int)$error['id']; ?>">返回后台报错详情</a><?php else: ?><a href="<?php echo urlError($error['id']); ?>">返回报错详情</a><?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="text-muted" style="margin-bottom: 16px; line-height: 1.7;">
                        你可以直接修改报错内容；普通用户提交后需要管理员审核，管理员修改会直接生效。
                        <?php if (!$fromAdmin): ?>
                        提交后会先进入待审核状态，审核通过前只显示修改记录，不会覆盖线上内容。
                        <?php endif; ?>
                    </div>
                    <form method="post" enctype="multipart/form-data" onsubmit="return confirm('确认提交本次修改吗？提交后将进入审核或直接生效。');">
                        <?php echo csrf_input($fromAdmin ? 'admin_form' : 'edit_error_form'); ?>
                        <div class="form-group">
                            <label class="form-label">所属游戏</label>
                            <input type="text" class="form-input" value="<?php echo hs($error['game_title']); ?><?php if ($error['vndb_id']): ?> (<?php echo h($error['vndb_id']); ?>)<?php endif; ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label class="form-label">报错分类 *</label>
                            <select name="category_id" class="form-select" required>
                                <option value="">请选择分类</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo $category['id']; ?>" <?php echo ($category['id'] == $error['category_id']) ? 'selected' : ''; ?>>
                                        <?php echo h($category['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">报错标题 *</label>
                            <input type="text" name="title" class="form-input" placeholder="例如：启动闪退、0xc000007b、字体乱码等" required value="<?php echo h($error['title']); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">错误现象 *</label>
                            <textarea name="phenomenon" class="form-textarea" rows="4" placeholder="详细描述错误现象、弹窗内容、出现时机等" required><?php echo h($error['phenomenon']); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label class="form-label">游戏引擎信息</label>
                            <input type="text" name="engine_info" class="form-input" placeholder="例如：Kirikiri2（KRKR2）、BGI、Artemis" value="<?php echo h($error['engine_info'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">系统分类 *</label>
                            <?php $selectedSystemCategory = $error['system_category'] ?? 'other'; if (!isset($systemCategoryOptions[$selectedSystemCategory])) $selectedSystemCategory = 'other'; ?>
                            <select name="system_category" id="system_category_select" class="form-select" required>
                                <?php foreach ($systemCategoryOptions as $value => $label): ?>
                                    <option value="<?php echo h($value); ?>" <?php echo $selectedSystemCategory === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">系统信息 *</label>
                            <input type="text" name="system_info" id="system_info_input" class="form-input" placeholder="例如：Windows10 64位 / Windows11 专业版" required value="<?php echo h($error['system_info']); ?>">
                        </div>
                        <div id="android-extra-fields" style="display:none;">
                            <div class="form-group">
                                <label class="form-label">手机处理器 *</label>
                                <input type="text" name="android_cpu" id="android_cpu_input" class="form-input" placeholder="例如 骁龙8 Gen2 / 天玑9200" value="<?php echo h($error['android_cpu'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">手机机型 *</label>
                                <input type="text" name="android_model" id="android_model_input" class="form-input" placeholder="例如 小米13 / 华为p90 / 一加ACE3" value="<?php echo h($error['android_model'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">安卓版本 *</label>
                                <input type="text" name="android_version" id="android_version_input" class="form-input" placeholder="例如 安卓13 / 安卓14" value="<?php echo h($error['android_version'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">汉化补丁</label>
                            <input type="text" name="patch_info" class="form-input" placeholder="汉化组名称、版本号等" value="<?php echo h($error['patch_info']); ?>">
                        </div>
                        <?php if (!empty($currentScreenshots)): ?>
                        <div class="form-group">
                            <label class="form-label">现有报错截图</label>
                            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                                <?php foreach ($currentScreenshots as $screenshot): ?>
                                    <div style="text-align: center;">
                                        <img src="<?php echo h(UPLOAD_URL . $screenshot); ?>" alt="报错截图" style="width: 120px; height: 90px; object-fit: cover; border-radius: 4px; border: 1px solid var(--glass-border); display: block; margin-bottom: 4px; cursor: pointer;" onclick="window.open(this.src)">
                                        <label style="font-size: 12px; cursor: pointer;">
                                            <input type="checkbox" name="keep_screenshots[]" value="<?php echo h($screenshot); ?>" checked>
                                            保留
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted">取消勾选的截图会在审核通过后被移除</small>
                        </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <label class="form-label">上传新报错截图（可选，最多5张）</label>
                            <input type="file" name="screenshots[]" class="form-input" accept="image/*" multiple>
                            <small class="text-muted">支持 JPG、PNG、GIF、WEBP 格式，单个文件最大 4MB</small>
                        </div>
                        <div class="form-group">
                            <div class="btn-group">
                                <button type="submit" class="btn">提交修改</button>
                                <a href="<?php echo $fromAdmin ? ('/error_detail.php?id=' . (int)$errorId . '&from_admin=1') : urlGame($error['game_id']); ?>" class="btn btn-secondary">取消</a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>
    <script>
    // 系统分类切换时更新系统信息占位提示
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
                ['android_cpu_input', 'android_model_input', 'android_version_input'].forEach(function(id) {
                    var el = document.getElementById(id);
                    if (!el) return;
                    el.required = isAndroid;
                    if (!isAndroid) el.value = '';
                });
            }
        }

        categorySelect.addEventListener('change', updateSystemPlaceholder);
        updateSystemPlaceholder();
    })();
    </script>
</body>
</html>
<?php
$pageHtml = ob_get_clean();
view('front/edit_error.twig', ['page_html' => $pageHtml]);

