<?php
require_once 'includes/user_auth.php';
require_once 'includes/image_utils.php';
require_once 'includes/sensitive_filter.php';
require_once 'includes/auth.php';
require_once 'includes/view.php';

$fromAdmin = isset($_GET['from_admin']) && $_GET['from_admin'] === '1';
if ($fromAdmin) {
    checkLogin();
    requirePermission('games', 'edit');
} else {
    requireUserLogin();
}

$pdo = getDB();
$message = '';
$messageType = '';
$isAdminUser = canCurrentUserBypassModeration() || $fromAdmin;
$actorUserId = $fromAdmin ? intval($_SESSION['admin_id'] ?? 0) : intval(getCurrentUserId());
$editId = intval($_GET['edit_id'] ?? $_POST['edit_id'] ?? 0);
$editGame = null;
$isEditMode = false;
$isOriginalSubmitter = false;
$canDirectEditGame = false;

if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM games WHERE id = ? AND status = 'approved'");
    $stmt->execute([$editId]);
    $editGame = $stmt->fetch();
    if (!$editGame) {
        header('Location: /');
        exit;
    }
    $isEditMode = true;
    $isOriginalSubmitter = !$fromAdmin && !empty($editGame['user_id']) && (int)$editGame['user_id'] === (int)getCurrentUserId();
    $canDirectEditGame = $isAdminUser || $isOriginalSubmitter;
}

// AJAX: 裁剪图片上传
$action = $_GET['action'] ?? '';
if ($action === 'upload_cropped' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = handleCroppedCoverData($_POST['image_data'] ?? '');
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// AJAX: VNDB 查询
if ($action === 'fetch_vndb' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $vndbId = trim($_POST['vndb_id'] ?? '');
    if ($vndbId) {
        // 先检查是否已存在（编辑当前游戏时允许查询原 VNDB ID）
        $stmt = $pdo->prepare("SELECT id, status FROM games WHERE vndb_id = ?");
        $stmt->execute([$vndbId]);
        $existing = $stmt->fetch();

        if ($existing && (!$isEditMode || (int)$existing['id'] !== (int)$editId)) {
            if ($existing['status'] === 'approved') {
                $result = ['success' => false, 'message' => '该游戏已存在于游戏库中'];
            } elseif ($existing['status'] === 'pending') {
                $result = ['success' => false, 'message' => '该游戏已提交，正在等待审核'];
            } else {
                $result = ['success' => false, 'message' => '该游戏之前的提交被拒绝，如有疑问请联系管理员'];
            }
        } else {
            $result = fetchVNDBInfo($vndbId);
        }

        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
}

// 处理游戏提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate_request('submit_game_form')) {
        $message = '请求已过期，请刷新后重试';
        $messageType = 'error';
    } elseif (!$fromAdmin && isUserBanned()) {
        $message = '您的账户已被封禁，无法提交内容';
        $messageType = 'error';
    } else {
    $vndbId = trim($_POST['vndb_id'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $titleJp = trim($_POST['title_jp'] ?? '');
    $romaji = trim($_POST['romaji'] ?? '');
    $aliases = trim($_POST['aliases'] ?? '');
    $developer = trim($_POST['developer'] ?? '');
    $releaseDate = trim($_POST['release_date'] ?? '');
    $platforms = trim($_POST['platforms'] ?? '');
    $coverUrl = trim($_POST['cover_url'] ?? '');
    $coverVndbUrl = trim($_POST['cover_vndb_url'] ?? '');
    $coverType = trim($_POST['cover_type'] ?? 'vndb');

    if (empty($vndbId) || empty($title)) {
        $message = '请填写 VNDB ID 和游戏标题';
        $messageType = 'error';
    } elseif (!preg_match('/^v\d+$/', $vndbId)) {
        $message = 'VNDB ID 格式错误，应为 v+数字（如 v5）';
        $messageType = 'error';
    }

    if ($messageType !== 'error') {
        // 检查重复
        $stmt = $pdo->prepare("SELECT id, status FROM games WHERE vndb_id = ?");
        $stmt->execute([$vndbId]);
        $existing = $stmt->fetch();

        if ($existing && (!$isEditMode || (int)$existing['id'] !== (int)$editId)) {
            if ($existing['status'] === 'approved') {
                $message = '该游戏已存在于游戏库中';
            } elseif ($existing['status'] === 'pending') {
                $message = '该游戏已提交，正在等待审核';
            } else {
                $message = '该游戏之前的提交被拒绝，如有疑问请联系管理员';
            }
            $messageType = 'error';
        } else {
            // 处理封面图片（编辑模式默认保留旧封面，裁剪后的路径优先）
            $coverImage = $isEditMode ? (string)($editGame['cover_image'] ?? '') : '';
            $vndbCoverUrlValue = $isEditMode ? ($editGame['vndb_cover_url'] ?? null) : null;
            $croppedPath = trim($_POST['cropped_cover_path'] ?? '');
            $hasCoverChange = false;

            if (!empty($croppedPath) && strpos($croppedPath, UPLOAD_PATH . 'covers/') === 0
                && !preg_match('/\.\./', $croppedPath) && file_exists(BASE_PATH . $croppedPath)) {
                $coverImage = $croppedPath;
                $vndbCoverUrlValue = null;
                $hasCoverChange = true;
            } elseif ($coverType === 'upload' && isset($_FILES['cover_file']) && $_FILES['cover_file']['error'] === UPLOAD_ERR_OK) {
                $upload = handleCoverUpload($_FILES['cover_file']);
                if ($upload['success']) {
                    $coverImage = $upload['path'];
                    $vndbCoverUrlValue = null;
                    $hasCoverChange = true;
                } else {
                    $message = $upload['message'];
                    $messageType = 'error';
                }
            } elseif ($coverType === 'url' && !empty($coverUrl)) {
                $coverImage = $coverUrl;
                $vndbCoverUrlValue = null;
                $hasCoverChange = true;
            } elseif ($coverType === 'vndb' && !empty($coverVndbUrl)) {
                // 服务端二次验证 VNDB 封面 R18 内容
                $vndbCheck = fetchVNDBInfo($vndbId);
                if ($vndbCheck['success'] && isset($vndbCheck['data']['cover_sexual']) && $vndbCheck['data']['cover_sexual'] >= 1.3) {
                    $message = 'VNDB 封面含有 R18 内容（sexual=' . round($vndbCheck['data']['cover_sexual'], 1) . '），禁止使用。请选择其他方式提供健全封面。';
                    $messageType = 'error';
                } else {
                    $coverImage = $coverVndbUrl;
                    $vndbCoverUrlValue = $coverVndbUrl;
                    $hasCoverChange = true;
                }
            }

            if ($messageType !== 'error') {
                $oldGameData = $isEditMode ? [
                    'vndb_id' => $editGame['vndb_id'] ?? '',
                    'title' => $editGame['title'] ?? '',
                    'title_jp' => $editGame['title_jp'] ?? '',
                    'romaji' => $editGame['romaji'] ?? '',
                    'aliases' => $editGame['aliases'] ?? '',
                    'developer' => $editGame['developer'] ?? '',
                    'release_date' => $editGame['release_date'] ?? '',
                    'platforms' => $editGame['platforms'] ?? '',
                    'cover_image' => $editGame['cover_image'] ?? '',
                    'vndb_cover_url' => $editGame['vndb_cover_url'] ?? '',
                ] : [];
                $newGameData = [
                    'vndb_id' => $vndbId,
                    'title' => $title,
                    'title_jp' => $titleJp,
                    'romaji' => $romaji,
                    'aliases' => $aliases ?: '',
                    'developer' => $developer,
                    'release_date' => $releaseDate ?: '',
                    'platforms' => $platforms,
                    'cover_image' => $coverImage,
                    'vndb_cover_url' => $vndbCoverUrlValue ?: '',
                ];

                if ($isEditMode && $oldGameData == $newGameData) {
                    $message = '未检测到任何修改';
                    $messageType = 'error';
                } elseif ($isEditMode) {
                    if ($canDirectEditGame) {
                        $stmt = $pdo->prepare("UPDATE games SET vndb_id=?, title=?, title_jp=?, romaji=?, aliases=?, developer=?, release_date=?, cover_image=?, vndb_cover_url=?, platforms=?, has_pending_revision=0 WHERE id=?");
                        $result = $stmt->execute([$vndbId, $title, $titleJp, $romaji, $aliases ?: null, $developer, $releaseDate ?: null, $coverImage, $vndbCoverUrlValue, $platforms, $editId]);
                        if ($result) {
                            $pdo->prepare("INSERT INTO game_revisions (game_id, user_id, user_ip, old_data, new_data, status, created_at) VALUES (?, ?, ?, ?, ?, 'approved', NOW())")
                                ->execute([$editId, $actorUserId, getClientIP(), json_encode($oldGameData, JSON_UNESCAPED_UNICODE), json_encode($newGameData, JSON_UNESCAPED_UNICODE)]);
                            $message = '游戏信息修改已直接生效。';
                            $messageType = 'success';
                            $_POST = [];
                        } else {
                            $message = '提交失败，请重试';
                            $messageType = 'error';
                        }
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO game_revisions (game_id, user_id, user_ip, old_data, new_data, status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())");
                        $result = $stmt->execute([$editId, $actorUserId, getClientIP(), json_encode($oldGameData, JSON_UNESCAPED_UNICODE), json_encode($newGameData, JSON_UNESCAPED_UNICODE)]);
                        if ($result) {
                            $pdo->prepare("UPDATE games SET has_pending_revision = 1 WHERE id = ?")->execute([$editId]);
                            $message = '修改已提交，待管理员审核后生效。';
                            $messageType = 'success';
                            $_POST = [];
                        } else {
                            $message = '提交失败，请重试';
                            $messageType = 'error';
                        }
                    }
                } else {
                // 确定 vndb_cover_url（VNDB 封面备份）
                if ($coverType === 'vndb' && !empty($coverVndbUrl)) {
                    $vndbCoverUrlValue = $coverVndbUrl;
                }

                $submitStatus = getCurrentUserModerationStatus();
                $stmt = $pdo->prepare("
                    INSERT INTO games (vndb_id, title, title_jp, romaji, aliases, developer, release_date, cover_image, vndb_cover_url, platforms, status, submitted_by_ip, user_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $result = $stmt->execute([
                    $vndbId, $title, $titleJp, $romaji, $aliases ?: null, $developer,
                    $releaseDate ?: null, $coverImage, $vndbCoverUrlValue, $platforms, $submitStatus, getClientIP(), $actorUserId
                ]);

                if ($result) {
                    $message = $isAdminUser ? '游戏提交成功！已直接发布到游戏库。' : '游戏提交成功！请等待管理员审核通过后即可在游戏库中查看。';
                    $messageType = 'success';
                    $_POST = [];
                } else {
                    $message = '提交失败，请重试';
                    $messageType = 'error';
                }
                }
            }
        }
    }
    }
}
ob_start();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isEditMode ? '编辑游戏' : '提交游戏'; ?> - <?php echo h(SITE_NAME); ?></title>
    <link rel="stylesheet" href="/assets/css/style.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="/assets/css/user.css?v=<?php echo ASSETS_VER; ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
    <?php renderSiteHead(); ?>
</head>
<body class="has-fixed-nav">
    <?php include 'includes/header.php'; ?>

    <?php if ($fromAdmin): ?>
    <div class="admin-layout">
        <?php renderAdminSidebar('games.php'); ?>
        <div class="admin-content">
            <main class="admin-main">
    <?php else: ?>
    <?php renderAnnouncement(); ?>

    <!-- 主要内容 -->
    <main class="main">
    <?php endif; ?>
        <div class="container">
            <div class="card">
                <div class="card-header"><?php echo $isEditMode ? '编辑游戏信息' : '提交新游戏'; ?></div>
                <div class="card-body">
                    <?php if ($message): ?>
                        <div class="alert-<?php echo $messageType; ?>">
                            <?php echo h($message); ?>
                        </div>
                    <?php endif; ?>

                    <p class="text-muted" style="margin-bottom: 20px;"><?php echo $isEditMode ? ($canDirectEditGame ? '正在编辑已发布游戏信息，提交后将直接生效。' : '正在编辑已发布游戏信息，提交后需管理员审核通过才会生效。') : ($isAdminUser ? '输入 VNDB 游戏编号，系统将自动获取游戏信息。管理员提交后将直接显示在游戏库中。' : '输入 VNDB 游戏编号，系统将自动获取游戏信息。提交后需管理员审核通过才会显示在游戏库中。'); ?></p>
                    <?php if ($fromAdmin): ?>
                        <p class="text-muted" style="margin-bottom: 12px;">
                            <a href="/admin/games.php" class="btn btn-secondary btn-sm">返回后台游戏管理</a>
                        </p>
                    <?php endif; ?>

                    <form method="post" enctype="multipart/form-data" id="gameForm">
                        <?php echo csrf_input('submit_game_form'); ?>
                        <?php echo csrf_input('submit_game_form'); ?>
                        <?php if ($isEditMode): ?>
                            <input type="hidden" name="edit_id" value="<?php echo intval($editId); ?>">
                        <?php endif; ?>
                        <!-- VNDB ID 查询 -->
                        <div class="form-group">
                            <label class="form-label">VNDB 游戏编号 *</label>
                            <div class="inline-field-group">
                                <input type="text" name="vndb_id" class="form-input" id="vndb_id" placeholder="例如：v5" value="<?php echo h($_POST['vndb_id'] ?? ($editGame['vndb_id'] ?? '')); ?>" required>
                                <button type="button" class="btn btn-secondary" id="fetchBtn" onclick="fetchVNDBData()">查询</button>
                            </div>
                            <small class="text-muted">输入编号后点击查询，将自动填充游戏信息</small>
                            <div id="fetchResult" style="margin-top: 10px; display: none;"></div>
                        </div>

                        <!-- 游戏标题 -->
                        <div class="form-group">
                            <label class="form-label">游戏标题（英文/中文） *</label>
                            <input type="text" name="title" class="form-input" id="title" required value="<?php echo h($_POST['title'] ?? ($editGame['title'] ?? '')); ?>">
                        </div>

                        <!-- 日文原名 -->
                        <div class="form-group">
                            <label class="form-label">日文原名</label>
                            <input type="text" name="title_jp" class="form-input" id="title_jp" value="<?php echo h($_POST['title_jp'] ?? ($editGame['title_jp'] ?? '')); ?>">
                        </div>

                        <!-- 罗马音 -->
                        <div class="form-group">
                            <label class="form-label">日语罗马音</label>
                            <input type="text" name="romaji" class="form-input" id="romaji" value="<?php echo h($_POST['romaji'] ?? ($editGame['romaji'] ?? '')); ?>">
                        </div>

                        <!-- 别名 -->
                        <div class="form-group">
                            <label class="form-label">别名</label>
                            <input type="text" name="aliases" class="form-input" id="aliases" value="<?php echo h($_POST['aliases'] ?? ($editGame['aliases'] ?? '')); ?>">
                            <small class="text-muted">多个别名用逗号分隔，查询 VNDB 时自动获取</small>
                        </div>

                        <!-- 开发商 -->
                        <div class="form-group">
                            <label class="form-label">开发商</label>
                            <input type="text" name="developer" class="form-input" id="developer" value="<?php echo h($_POST['developer'] ?? ($editGame['developer'] ?? '')); ?>">
                        </div>

                        <!-- 发售日 -->
                        <div class="form-group">
                            <label class="form-label">发售日</label>
                            <input type="date" name="release_date" class="form-input" id="release_date" value="<?php echo h($_POST['release_date'] ?? ($editGame['release_date'] ?? '')); ?>">
                        </div>

                        <!-- 平台 -->
                        <div class="form-group">
                            <label class="form-label">平台</label>
                            <input type="text" name="platforms" class="form-input" id="platforms" value="<?php echo h($_POST['platforms'] ?? ($editGame['platforms'] ?? '')); ?>">
                        </div>

                        <!-- 封面图片 -->
                        <div class="form-group">
                            <label class="form-label">封面图片</label>
                            <div class="alert-error" style="margin-bottom: 12px; font-size: 13px; line-height: 1.6;">
                                <strong>R18 封面禁止提交！</strong>无论使用哪种方式提供封面，均禁止包含色情、裸露等不适内容。违规提交将被拒绝。
                            </div>
                            <div class="radio-group">
                                <?php if ($isEditMode): ?>
                                <label class="radio-label">
                                    <input type="radio" name="cover_type" value="keep" checked onchange="toggleCoverInput()"> 保留当前封面
                                </label>
                                <?php endif; ?>
                                <label class="radio-label">
                                    <input type="radio" name="cover_type" value="vndb" <?php echo $isEditMode ? '' : 'checked'; ?> onchange="toggleCoverInput()"> 使用 VNDB 封面
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="cover_type" value="url" onchange="toggleCoverInput()"> 输入图片 URL
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="cover_type" value="upload" onchange="toggleCoverInput()"> 上传本地图片
                                </label>
                            </div>
                            <!-- 各封面类型的提示信息 -->
                            <?php if ($isEditMode): ?>
                                <?php $editCoverUrl = getCoverUrl($editGame, true); ?>
                                <?php if ($editCoverUrl): ?>
                                    <div style="margin-bottom: 12px; display: flex; align-items: flex-start; gap: 12px;">
                                        <img src="<?php echo h($editCoverUrl); ?>" alt="当前封面" style="width: 96px; max-height: 140px; object-fit: contain; border-radius: 4px; border: 1px solid var(--glass-border); background: var(--bg-tertiary);">
                                        <span class="text-muted" style="font-size: 13px;">当前封面。选择“保留当前封面”时不会更改封面。</span>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <div id="cover_tip" class="cover-tip"></div>
                            <!-- 裁剪后路径隐藏字段 -->
                            <input type="hidden" name="cropped_cover_path" id="cropped_cover_path" value="">
                            <div class="cover-crop-mode" id="cover_crop_mode" style="display: <?php echo $isEditMode ? 'none' : ''; ?>;">
                                <span class="text-muted">裁剪方向：</span>
                                <label class="radio-label"><input type="radio" name="cover_crop_orientation" value="portrait" checked onchange="setCoverCropOrientation('portrait')"> 竖屏封面</label>
                                <label class="radio-label"><input type="radio" name="cover_crop_orientation" value="landscape" onchange="setCoverCropOrientation('landscape')"> 横屏封面</label>
                            </div>
                            <div id="cover_vndb_wrap" style="display: <?php echo $isEditMode ? 'none' : ''; ?>;">
                                <input type="hidden" name="cover_vndb_url" id="cover_vndb_url" value="">
                                <div id="vndb_cover_preview" class="text-muted">查询 VNDB 后将自动获取封面，R18 封面会被自动过滤</div>
                                <div id="vndb_crop_btn_wrap" style="display: none; margin-top: 8px;">
                                    <button type="button" class="btn-crop" onclick="openCropperVndb()">裁剪封面</button>
                                </div>
                            </div>
                            <div id="cover_url_wrap" style="display: none;">
                                <div class="inline-field-group" style="margin-bottom: 8px;">
                                    <input type="url" name="cover_url" class="form-input" id="cover_url" placeholder="输入图片链接地址">
                                    <button type="button" class="btn-crop" onclick="loadAndCropUrl()">加载并裁剪</button>
                                </div>
                                <div id="url_preview" style="display: none;"></div>
                            </div>
                            <div id="cover_file_wrap" style="display: none;">
                                <input type="file" name="cover_file" class="form-input" id="cover_file" accept="image/jpeg,image/png,image/webp" onchange="onFileSelected(this)">
                                <div id="file_crop_btn_wrap" style="display: none; margin-top: 8px;">
                                    <button type="button" class="btn-crop" onclick="openCropperFile()">裁剪封面</button>
                                </div>
                            </div>
                            <!-- 裁剪状态 -->
                            <div id="crop_status" class="crop-status" style="display: none;"></div>
                        </div>

                        <!-- 提交按钮 -->
                        <div class="form-group">
                            <div class="btn-group">
                                <button type="submit" name="submit_game" class="btn"><?php echo $isEditMode ? '提交修改' : '提交游戏'; ?></button>
                                <a href="<?php echo $isEditMode ? urlGame($editId) : '/'; ?>" class="btn btn-secondary"><?php echo $isEditMode ? '取消编辑' : '返回首页'; ?></a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php if ($fromAdmin): ?>
            </main>
        </div>
    </div>
    <?php renderAdminFooterScripts(); ?>
    <?php else: ?>
    </main>
    <?php endif; ?>

    <?php include 'includes/footer.php'; ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
    <script src="/assets/js/cover-cropper.js?v=<?php echo ASSETS_VER; ?>"></script>
    <script>
        // 初始化裁剪组件
        var coverCropper = new CoverCropper({
            uploadUrl: '?action=upload_cropped',
            proxyUrl: '/image_proxy?url=',
            croppedPathInput: 'cropped_cover_path',
            aspectRatio: 5 / 7,
            outputWidth: 500,
            title: '裁剪游戏封面',
            confirmText: '确认封面裁剪',
            outputQuality: 0.9,
            onCropped: function(path, base64Data) {
                updateCoverPreview(base64Data, true);
                var statusEl = document.getElementById('crop_status');
                statusEl.style.display = '';
                statusEl.innerHTML = '<img class="crop-preview-thumb ' + (currentCoverOrientation === 'landscape' ? 'crop-preview-landscape' : '') + '" src="' + base64Data + '"> 封面已裁剪';
            }
        });

        // 保存当前 VNDB 封面 URL（供裁剪使用）
        var currentVndbCoverUrl = '';
        // 保存当前选中的本地文件
        var currentSelectedFile = null;
        var currentCoverOrientation = 'portrait';

        function setCoverCropOrientation(orientation) {
            currentCoverOrientation = orientation === 'landscape' ? 'landscape' : 'portrait';
            if (currentCoverOrientation === 'landscape') {
                coverCropper.setAspectRatio(16 / 9, 960, 540);
            } else {
                coverCropper.setAspectRatio(5 / 7, 500, 700);
            }
            updatePreviewOrientationClass();
            coverCropper.reset();
        }

        function updatePreviewOrientationClass() {
            document.querySelectorAll('.cover-preview-image').forEach(function(img) {
                img.classList.toggle('cover-preview-image-landscape', currentCoverOrientation === 'landscape');
                img.classList.toggle('cover-preview-image-portrait', currentCoverOrientation !== 'landscape');
            });
        }

        function updateCoverPreview(src, isCropped) {
            var previewDiv = document.getElementById('vndb_cover_preview');
            if (!previewDiv) return;
            var message = isCropped ? '已裁剪封面（提交时将使用此图片）' : '已获取 VNDB 封面（可点击下方按钮裁剪）';
            previewDiv.innerHTML = '<div class="cover-preview-wrap">'
                + '<img src="' + src + '" class="cover-preview-image ' + (currentCoverOrientation === 'landscape' ? 'cover-preview-image-landscape' : 'cover-preview-image-portrait') + '">'
                + '<span class="cover-preview-message">' + message + '</span></div>';
        }

        setCoverCropOrientation('portrait');

        var coverTips = {
            keep: '将保留当前封面不做更改。若需更换封面，请选择其他选项。',
            vndb: '使用 VNDB 封面：系统将自动获取封面图片，R18 内容自动过滤。获取后可选择竖屏或横屏裁剪。',
            url: '输入图片 URL：输入链接后点击“加载并裁剪”，可选择竖屏或横屏裁剪。禁止 R18 内容。',
            upload: '上传本地图片：支持 JPG/PNG/WEBP，最大 2MB。选择文件后可选择竖屏或横屏裁剪。禁止 R18 内容。'
        };

        function updateCoverTip(type) {
            var tipDiv = document.getElementById('cover_tip');
            tipDiv.textContent = coverTips[type] || '';
            tipDiv.style.display = coverTips[type] ? '' : 'none';
        }

        function toggleCoverInput() {
            var type = document.querySelector('input[name="cover_type"]:checked').value;
            document.getElementById('cover_vndb_wrap').style.display = type === 'vndb' ? '' : 'none';
            document.getElementById('cover_url_wrap').style.display = type === 'url' ? '' : 'none';
            document.getElementById('cover_file_wrap').style.display = type === 'upload' ? '' : 'none';
            var cropMode = document.getElementById('cover_crop_mode');
            if (cropMode) cropMode.style.display = type === 'keep' ? 'none' : '';
            if (type !== 'upload') {
                document.getElementById('cover_file').value = '';
                currentSelectedFile = null;
                document.getElementById('file_crop_btn_wrap').style.display = 'none';
            }
            if (type !== 'url') {
                document.getElementById('cover_url').value = '';
                document.getElementById('url_preview').style.display = 'none';
            }
            updateCoverTip(type);
            // 切换封面类型时清空裁剪状态
            coverCropper.reset();
        }

        // 页面加载时显示初始提示
        updateCoverTip(<?php echo json_encode($isEditMode ? 'keep' : 'vndb'); ?>);

        // VNDB 封面裁剪
        function openCropperVndb() {
            if (currentVndbCoverUrl) {
                coverCropper.open(currentVndbCoverUrl, 'vndb');
            }
        }

        // URL 封面加载并裁剪
        function loadAndCropUrl() {
            var url = document.getElementById('cover_url').value.trim();
            if (!url) {
                alert('请先输入图片链接地址');
                return;
            }
            coverCropper.open(url, 'url');
        }

        // 本地文件选择
        function onFileSelected(input) {
            if (input.files && input.files[0]) {
                currentSelectedFile = input.files[0];
                document.getElementById('file_crop_btn_wrap').style.display = '';
                // 清除之前的裁剪状态
                coverCropper.reset();
            } else {
                currentSelectedFile = null;
                document.getElementById('file_crop_btn_wrap').style.display = 'none';
            }
        }

        // 本地文件裁剪
        function openCropperFile() {
            if (currentSelectedFile) {
                coverCropper.open(currentSelectedFile, 'file');
            }
        }

        // 提交前确认检查
        document.getElementById('gameForm').addEventListener('submit', function(e) {
            if (!confirm(<?php echo json_encode($isEditMode ? '确认提交本次修改吗？提交后将进入审核或直接生效。' : '确认提交新游戏吗？提交后将进入审核或直接发布。'); ?>)) {
                e.preventDefault();
                return false;
            }

            var coverType = document.querySelector('input[name="cover_type"]:checked').value;
            var hasCropped = document.getElementById('cropped_cover_path').value !== '';

            // 如果已裁剪，不需要再确认
            if (hasCropped) return;

            if (coverType === 'url') {
                var urlVal = document.getElementById('cover_url').value.trim();
                if (urlVal && !confirm('请确认您输入的封面图片 URL 不包含 R18 内容。\n\n提交含有 R18 封面的游戏将被拒绝。\n\n是否继续提交？')) {
                    e.preventDefault();
                    return false;
                }
            }

            if (coverType === 'upload') {
                var fileVal = document.getElementById('cover_file').value;
                if (fileVal && !confirm('请确认您上传的封面图片不包含 R18 内容。\n\n提交含有 R18 封面的游戏将被拒绝。\n\n是否继续提交？')) {
                    e.preventDefault();
                    return false;
                }
            }
        });

        function fetchVNDBData() {
            var vndbId = document.getElementById('vndb_id').value.trim();
            if (!vndbId) {
                alert('请输入 VNDB 游戏编号');
                return;
            }
            if (!/^v\d+$/i.test(vndbId)) {
                vndbId = 'v' + vndbId.replace(/\D/g, '');
                document.getElementById('vndb_id').value = vndbId;
            }

            var btn = document.getElementById('fetchBtn');
            var resultDiv = document.getElementById('fetchResult');
            btn.disabled = true;
            btn.textContent = '查询中...';
            resultDiv.style.display = 'block';
            resultDiv.innerHTML = '<span class="text-muted">正在查询 VNDB...</span>';

            // 重置裁剪状态
            coverCropper.reset();
            currentVndbCoverUrl = '';
            document.getElementById('vndb_crop_btn_wrap').style.display = 'none';

            fetch('?action=fetch_vndb', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'vndb_id=' + encodeURIComponent(vndbId)
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.success) {
                    // 标题优先级：中文 → 日文 → 英文/罗马音
                    var displayTitle = data.data.title_zh || data.data.title_jp || data.data.title || '';
                    document.getElementById('title').value = displayTitle;
                    document.getElementById('title_jp').value = data.data.title_jp || '';
                    document.getElementById('romaji').value = data.data.romaji || '';
                    document.getElementById('aliases').value = data.data.aliases || '';
                    document.getElementById('developer').value = data.data.developer || '';
                    document.getElementById('release_date').value = data.data.release_date || '';
                    document.getElementById('platforms').value = data.data.platforms || '';

                    // 处理 VNDB 封面
                    var coverUrl = data.data.cover_url || '';
                    var coverSexual = data.data.cover_sexual || 0;
                    var previewDiv = document.getElementById('vndb_cover_preview');

                    if (coverUrl && coverSexual >= 1.3) {
                        // R18 封面，拦截
                        document.getElementById('cover_vndb_url').value = '';
                        currentVndbCoverUrl = '';
                        document.getElementById('vndb_crop_btn_wrap').style.display = 'none';
                        previewDiv.innerHTML = '<div class="alert-error" style="margin-bottom: 0;">'
                            + 'VNDB 封面含有不适内容（sexual=' + coverSexual.toFixed(1) + '），已自动过滤。请使用其他方式提供健全封面。</div>';
                    } else if (coverUrl) {
                        document.getElementById('cover_vndb_url').value = coverUrl;
                        currentVndbCoverUrl = coverUrl;
                        document.getElementById('vndb_crop_btn_wrap').style.display = '';
                        updateCoverPreview(coverUrl, false);
                    } else {
                        document.getElementById('cover_vndb_url').value = '';
                        currentVndbCoverUrl = '';
                        document.getElementById('vndb_crop_btn_wrap').style.display = 'none';
                        previewDiv.innerHTML = '<span class="text-muted">该游戏在 VNDB 上无封面，请手动输入 URL 或上传图片</span>';
                    }

                    resultDiv.innerHTML = '<span class="alert-success" style="padding: 6px 12px; display: inline-block; margin-bottom: 0;">已获取游戏信息：' + (data.data.title || '') + '</span>';
                } else {
                    resultDiv.innerHTML = '<span class="alert-error" style="padding: 6px 12px; display: inline-block;">' + data.message + '</span>';
                }
            })
            .catch(function(error) {
                resultDiv.innerHTML = '<span class="alert-error" style="padding: 6px 12px; display: inline-block;">请求失败：' + error.message + '</span>';
            })
            .finally(function() {
                btn.disabled = false;
                btn.textContent = '查询';
            });
        }
    </script>
</body>
</html>
<?php
$pageHtml = ob_get_clean();
view('front/submit_game.twig', ['page_html' => $pageHtml]);

