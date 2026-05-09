    <!-- 头部 -->
    <?php
    if (!function_exists('isUserLoggedIn')) {
        require_once __DIR__ . '/user_auth.php';
    }
    // 获取未读站内信数量
    $__unreadMsgCount = 0;
    $__unreadPmCount = 0;
    if (isUserLoggedIn()) {
        try {
            $__msgStmt = getDB()->prepare("SELECT COUNT(*) FROM messages WHERE user_id = ? AND is_read = 0");
            $__msgStmt->execute([getCurrentUserId()]);
            $__unreadMsgCount = (int)$__msgStmt->fetchColumn();

            $__pmStmt = getDB()->prepare("SELECT COUNT(*) FROM private_messages WHERE receiver_id = ? AND is_read = 0");
            $__pmStmt->execute([getCurrentUserId()]);
            $__unreadPmCount = (int)$__pmStmt->fetchColumn();
        } catch (Exception $e) {}
    }
    $__totalUnread = $__unreadMsgCount + $__unreadPmCount;
    ?>
    <header class="header">
        <div class="container">
            <a href="/" class="logo">
            <?php $__siteLogo = getSiteSetting('site_logo', ''); if ($__siteLogo): ?>
                <img src="/<?php echo h($__siteLogo); ?>" alt="" class="logo-img">
            <?php endif; ?>
            <?php echo h(getSiteSetting('site_name', SITE_NAME)); ?>
        </a>
            <nav class="nav">
                <a href="/">首页</a>
                <div class="nav-dropdown-wrapper">
                    <span class="nav-dropdown-trigger">文章</span>
                    <div class="nav-dropdown-menu"><div class="nav-dropdown-menu-inner">
                        <a href="/articles" class="nav-dropdown-menu-item">文章列表</a>
                        <?php if (isUserLoggedIn()): ?>
                            <a href="/submit_article" class="nav-dropdown-menu-item">提交文章</a>
                        <?php else: ?>
                            <a href="/login?redirect=<?php echo urlencode('/submit_article'); ?>" class="nav-dropdown-menu-item">提交文章</a>
                        <?php endif; ?>
                    </div></div>
                </div>
                <div class="nav-dropdown-wrapper">
                    <span class="nav-dropdown-trigger">游戏</span>
                    <div class="nav-dropdown-menu"><div class="nav-dropdown-menu-inner">
                        <a href="/games" class="nav-dropdown-menu-item">游戏列表</a>
                        <a href="/submit_game" class="nav-dropdown-menu-item">提交游戏</a>
                    </div></div>
                </div>
                <div class="nav-dropdown-wrapper">
                    <span class="nav-dropdown-trigger">报错/解决方案</span>
                    <div class="nav-dropdown-menu"><div class="nav-dropdown-menu-inner">
                        <a href="/submit" class="nav-dropdown-menu-item">提交报错/解决方案</a>
                    </div></div>
                </div>
                <div class="nav-dropdown-wrapper">
                    <span class="nav-dropdown-trigger">讨论区</span>
                    <div class="nav-dropdown-menu"><div class="nav-dropdown-menu-inner">
                        <a href="/discussions" class="nav-dropdown-menu-item">讨论区</a>
                        <?php if (isUserLoggedIn()): ?>
                            <a href="/submit_discussion" class="nav-dropdown-menu-item">提交话题</a>
                        <?php else: ?>
                            <a href="/login?redirect=<?php echo urlencode('/submit_discussion'); ?>" class="nav-dropdown-menu-item">提交话题</a>
                        <?php endif; ?>
                    </div></div>
                </div>
                <button class="theme-toggle" onclick="toggleTheme()" title="切换主题"></button>
                <?php if (isUserLoggedIn()): ?>
                    <div class="user-avatar-wrapper">
                        <button class="user-avatar-btn" type="button" aria-label="用户菜单">
                            <?php $avatarUrl = getUserAvatarUrl(); ?>
                            <?php if ($avatarUrl): ?>
                                <img src="<?php echo h($avatarUrl); ?>" class="user-avatar-nav" alt="">
                            <?php else: ?>
                                <div class="user-avatar-nav fallback"><?php echo h(getUserInitial()); ?></div>
                            <?php endif; ?>
                        </button>
                        <?php if ($__totalUnread > 0): ?>
                            <span class="avatar-unread-dot"></span>
                        <?php endif; ?>
                        <div class="user-dropdown">
                            <div class="user-dropdown-header">
                                <div class="user-dropdown-name"><?php echo h($_SESSION['user_username']); ?></div>
                                <span class="user-role-badge role-<?php echo h($_SESSION['user_role']); ?>"><?php echo h(getRoleLabel($_SESSION['user_role'])); ?></span>
                            </div>
                            <a href="/profile" class="user-dropdown-item">个人主页</a>
                            <a href="/messages" class="user-dropdown-item">站内信<?php if ($__unreadMsgCount > 0): ?><span class="dropdown-unread-dot"></span><?php endif; ?></a>
                            <a href="/private_messages" class="user-dropdown-item">消息通知<?php if ($__unreadPmCount > 0): ?><span class="dropdown-unread-dot"></span><?php endif; ?></a>
                            <?php if (isAdmin()): ?>
                                <a href="/admin/" class="user-dropdown-item">管理后台</a>
                            <?php endif; ?>
                            <div class="user-dropdown-divider"></div>
                            <a href="/logout" class="user-dropdown-item user-dropdown-logout">退出登录</a>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="/login" class="nav-auth-btn">登录</a>
                    <a href="/register" class="nav-auth-btn nav-auth-register">注册</a>
                <?php endif; ?>
            </nav>
            <button class="nav-mobile-toggle" aria-label="菜单"><span></span><span></span><span></span></button>
        </div>
    </header>
    <?php if (isUserLoggedIn()): ?>
        <div class="todo-floating-dock" id="todoFloatingDock">
            <button type="button" class="todo-floating-toggle" aria-label="展开网站待办" aria-expanded="false">
                <span aria-hidden="true">›</span>
            </button>
            <a href="/todos" class="todo-floating-entry" title="网站待办" aria-label="网站待办">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M8 2h8"/>
                    <path d="M9 2v3h6V2"/>
                    <rect x="5" y="4" width="14" height="18" rx="2"/>
                    <path d="M9 10h4"/>
                    <path d="M9 14h2"/>
                    <path d="M14.5 17.5 19 13l2 2-4.5 4.5-2.5.5.5-2.5Z"/>
                </svg>
            </a>
        </div>
        <script>
        (function() {
            var dock = document.getElementById('todoFloatingDock');
            if (!dock) return;
            var toggle = dock.querySelector('.todo-floating-toggle');
            var entry = dock.querySelector('.todo-floating-entry');
            var closeTimer = null;
            if (!toggle) return;

            function setExpanded(expanded) {
                dock.classList.toggle('is-open', expanded);
                toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                toggle.setAttribute('aria-label', expanded ? '收起网站待办' : '展开网站待办');
                window.clearTimeout(closeTimer);
                if (expanded) {
                    closeTimer = window.setTimeout(function() {
                        setExpanded(false);
                    }, 3000);
                }
            }

            toggle.addEventListener('click', function() {
                setExpanded(!dock.classList.contains('is-open'));
            });

            if (entry) {
                entry.addEventListener('click', function() {
                    window.clearTimeout(closeTimer);
                });
            }
        })();
        </script>
    <?php endif; ?>
