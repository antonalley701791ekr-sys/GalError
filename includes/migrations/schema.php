<?php
/**
 * 数据库迁移函数定义
 * ------------------------------------------------------------------
 * 这些函数原先散落在 includes/config.php 中（与配置/工具函数交错），现集中到此。
 * 全部保持“幂等”：内部先 SHOW 再 ALTER/CREATE，可安全重复执行。
 * 调用顺序由 includes/migrations/runner.php 的 runSchemaMigrations() 统一编排（顺序不可乱）。
 * 运行时依赖 config.php 提供的 getDB()；本文件只定义、不调用。
 */

// 自动迁移：确保 games 表有 aliases 字段
function ensureAliasesColumn() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW COLUMNS FROM games LIKE 'aliases'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE games ADD COLUMN aliases TEXT DEFAULT NULL AFTER romaji");
    }
}

// 自动迁移：确保 users 统一用户表存在，并迁移 admins 数据
function ensureUsersTable() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(30) NOT NULL UNIQUE,
            email VARCHAR(255) DEFAULT NULL,
            password VARCHAR(255) NOT NULL,
            role ENUM('user','sub','super') DEFAULT 'user',
            avatar VARCHAR(500) DEFAULT NULL,
            permissions TEXT DEFAULT NULL,
            enabled TINYINT(1) DEFAULT 1,
            email_verified TINYINT(1) DEFAULT 0,
            verify_token VARCHAR(64) DEFAULT NULL,
            verify_token_expires DATETIME DEFAULT NULL,
            username_changes INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 迁移 admins 表数据到 users 表
        $adminsExist = $pdo->query("SHOW TABLES LIKE 'admins'")->rowCount();
        if ($adminsExist) {
            $adminCount = $pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
            if ($adminCount > 0) {
                $pdo->exec("INSERT INTO users (id, username, email, password, role, avatar, permissions, enabled, email_verified, username_changes, created_at)
                    SELECT id, username, NULL, password,
                        CASE WHEN role = 'super' THEN 'super' WHEN role = 'sub' THEN 'sub' ELSE 'user' END,
                        avatar, permissions, enabled, 1, IFNULL(username_changes, 0), created_at
                    FROM admins");
                $maxId = $pdo->query("SELECT MAX(id) FROM users")->fetchColumn();
                if ($maxId) {
                    $pdo->exec("ALTER TABLE users AUTO_INCREMENT = " . ($maxId + 1));
                }
            }
        }
    }
}

// 自动迁移：确保 games 表有 user_id 字段
function ensureGamesUserId() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW COLUMNS FROM games LIKE 'user_id'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE games ADD COLUMN user_id INT DEFAULT NULL AFTER submitted_by_ip");
        $pdo->exec("ALTER TABLE games ADD INDEX idx_games_user_id (user_id)");
    }
}

// 自动迁移：确保 errors 表有 user_id 字段
function ensureErrorsUserId() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW COLUMNS FROM errors LIKE 'user_id'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE errors ADD COLUMN user_id INT DEFAULT NULL AFTER user_ip");
        $pdo->exec("ALTER TABLE errors ADD INDEX idx_errors_user_id (user_id)");
    }
}

// 自动迁移：确保 users 表有 banned 字段
function ensureUsersBannedColumn() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'banned'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN banned TINYINT(1) DEFAULT 0 AFTER enabled");
    }
}

// 自动迁移：确保 users 表有 last_activity_at 字段
function ensureUsersLastActivityColumn() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_activity_at'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN last_activity_at DATETIME DEFAULT NULL AFTER created_at");
    }

    $idx = $pdo->query("SHOW INDEX FROM users WHERE Key_name = 'idx_users_last_activity_at'");
    if ($idx->rowCount() === 0) {
        $pdo->exec("ALTER TABLE users ADD INDEX idx_users_last_activity_at (last_activity_at)");
    }
}

// 自动迁移：确保 error_revisions 修改记录表存在
function ensureErrorRevisionsTable() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW TABLES LIKE 'error_revisions'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE error_revisions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            error_id INT NOT NULL,
            user_id INT DEFAULT NULL,
            user_ip VARCHAR(45) DEFAULT '',
            old_data TEXT NOT NULL,
            new_data TEXT NOT NULL,
            old_screenshots TEXT,
            new_screenshots TEXT,
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            viewed_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_revision_error_id (error_id),
            INDEX idx_revision_status (status),
            INDEX idx_revision_viewed (status, viewed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

// 自动迁移：确保 error_revisions 有 viewed_at 字段（用于“已查看后不再提示红圈”）
function ensureErrorRevisionsViewedAt() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW COLUMNS FROM error_revisions LIKE 'viewed_at'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE error_revisions ADD COLUMN viewed_at DATETIME DEFAULT NULL AFTER status");
    }
}

// 自动迁移：确保 errors 表有 engine_info 字段
function ensureErrorsEngineInfo() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW COLUMNS FROM errors LIKE 'engine_info'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE errors ADD COLUMN engine_info VARCHAR(255) DEFAULT NULL AFTER phenomenon");
    }
}

// 自动迁移：确保 errors 表有 system_category 字段
function ensureErrorsSystemCategory() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW COLUMNS FROM errors LIKE 'system_category'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE errors ADD COLUMN system_category VARCHAR(32) DEFAULT NULL AFTER engine_info");
    }
}

// 自动迁移：确保 errors 表有安卓模拟器扩展字段
function ensureErrorsAndroidDeviceColumns() {
    $pdo = getDB();

    $stmt = $pdo->query("SHOW COLUMNS FROM errors LIKE 'android_cpu'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE errors ADD COLUMN android_cpu VARCHAR(120) DEFAULT NULL AFTER system_info");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM errors LIKE 'android_model'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE errors ADD COLUMN android_model VARCHAR(120) DEFAULT NULL AFTER android_cpu");
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM errors LIKE 'android_version'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE errors ADD COLUMN android_version VARCHAR(60) DEFAULT NULL AFTER android_model");
    }
}

// 自动迁移：确保 error_revisions 表有 engine_info 快照字段
function ensureErrorRevisionsEngineInfo() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW COLUMNS FROM error_revisions LIKE 'old_engine_info'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE error_revisions ADD COLUMN old_engine_info VARCHAR(255) DEFAULT NULL AFTER old_data");
        $pdo->exec("ALTER TABLE error_revisions ADD COLUMN new_engine_info VARCHAR(255) DEFAULT NULL AFTER new_data");
    }
}

// 自动迁移：确保 errors 表有 solution_screenshots 字段
function ensureErrorsSolutionScreenshots() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW COLUMNS FROM errors LIKE 'solution_screenshots'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE errors ADD COLUMN solution_screenshots TEXT DEFAULT NULL AFTER screenshots");
    }
}

// 自动迁移：确保 error_solutions 表存在
function ensureErrorSolutionsTable() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW TABLES LIKE 'error_solutions'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE error_solutions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            error_id INT NOT NULL,
            solution_no INT DEFAULT NULL,
            user_id INT NOT NULL,
            solution LONGTEXT NOT NULL,
            status ENUM('pending','approved','rejected') DEFAULT 'approved',
            reject_reason TEXT DEFAULT NULL,
            is_primary TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_error_solutions_error_id (error_id),
            INDEX idx_error_solutions_solution_no (error_id, solution_no),
            INDEX idx_error_solutions_user_id (user_id),
            INDEX idx_error_solutions_status (status),
            INDEX idx_error_solutions_primary (error_id, is_primary)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return;
    }

    $cols = $pdo->query("SHOW COLUMNS FROM error_solutions LIKE 'is_primary'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE error_solutions ADD COLUMN is_primary TINYINT(1) DEFAULT 1 AFTER reject_reason");
    }

    $cols = $pdo->query("SHOW COLUMNS FROM error_solutions LIKE 'solution_no'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE error_solutions ADD COLUMN solution_no INT DEFAULT NULL AFTER error_id");
        $pdo->exec("ALTER TABLE error_solutions ADD INDEX idx_error_solutions_solution_no (error_id, solution_no)");
    }
}

// 自动迁移：将旧 errors.solution 回填到 error_solutions（若旧字段仍存在）
function migrateLegacyErrorSolutions() {
    $pdo = getDB();

    $stmt = $pdo->query("SHOW COLUMNS FROM errors LIKE 'solution'");
    if ($stmt->rowCount() === 0) {
        return;
    }

    $legacyStmt = $pdo->prepare("
        SELECT e.id, e.user_id, e.solution, e.status, e.created_at
        FROM errors e
        WHERE e.solution IS NOT NULL AND e.solution != ''
          AND NOT EXISTS (
              SELECT 1 FROM error_solutions es
              WHERE es.error_id = e.id AND es.is_primary = 1
          )
    ");
    $legacyStmt->execute();
    $rows = $legacyStmt->fetchAll();
    if (empty($rows)) {
        return;
    }

    $ins = $pdo->prepare("INSERT INTO error_solutions (error_id, user_id, solution, status, is_primary, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)");
    foreach ($rows as $row) {
        $solution = trim((string)($row['solution'] ?? ''));
        if ($solution === '') {
            continue;
        }
        $status = ((string)($row['status'] ?? '') === 'approved') ? 'approved' : 'pending';
        $createdAt = (string)($row['created_at'] ?? date('Y-m-d H:i:s'));
        $ins->execute([
            (int)$row['id'],
            (int)$row['user_id'],
            $solution,
            $status,
            $createdAt,
            $createdAt,
        ]);
    }
}

// 自动迁移：移除旧 errors.solution 字段，彻底废弃旧结构
function dropLegacyErrorSolutionColumn() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW COLUMNS FROM errors LIKE 'solution'");
    if ($stmt->rowCount() > 0) {
        $pdo->exec("ALTER TABLE errors DROP COLUMN solution");
    }
}

// 自动迁移：确保 error_revisions 表有 solution_screenshots 相关字段
function ensureRevisionsSolutionScreenshots() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW COLUMNS FROM error_revisions LIKE 'old_solution_screenshots'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE error_revisions ADD COLUMN old_solution_screenshots TEXT DEFAULT NULL AFTER new_screenshots");
        $pdo->exec("ALTER TABLE error_revisions ADD COLUMN new_solution_screenshots TEXT DEFAULT NULL AFTER old_solution_screenshots");
    }
}

// 自动迁移：确保 errors 表有 reject_reason 字段
function ensureErrorsRejectReason() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW COLUMNS FROM errors LIKE 'reject_reason'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE errors ADD COLUMN reject_reason TEXT DEFAULT NULL AFTER status");
    }
}

// 自动迁移：确保 games 表有 reject_reason 字段
function ensureGamesRejectReason() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW COLUMNS FROM games LIKE 'reject_reason'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE games ADD COLUMN reject_reason TEXT DEFAULT NULL AFTER status");
    }
}

// 自动迁移：确保 games 表有 has_pending_revision 字段
function ensureGamesPendingRevisionColumn() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW COLUMNS FROM games LIKE 'has_pending_revision'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE games ADD COLUMN has_pending_revision TINYINT(1) DEFAULT 0 AFTER reject_reason");
    }
}

// 自动迁移：确保 game_revisions 游戏修改记录表存在
function ensureGameRevisionsTable() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW TABLES LIKE 'game_revisions'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE game_revisions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            game_id INT NOT NULL,
            user_id INT DEFAULT NULL,
            user_ip VARCHAR(45) DEFAULT '',
            old_data TEXT NOT NULL,
            new_data TEXT NOT NULL,
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            reject_reason TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_game_revision_game_id (game_id),
            INDEX idx_game_revision_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

// 自动迁移：确保 error_revisions 表有 reject_reason 字段
function ensureErrorRevisionsRejectReason() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW COLUMNS FROM error_revisions LIKE 'reject_reason'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE error_revisions ADD COLUMN reject_reason TEXT DEFAULT NULL AFTER status");
    }
}

// 自动迁移：确保 articles 文章表存在
function ensureArticlesTable() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW TABLES LIKE 'articles'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE articles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            content LONGTEXT NOT NULL,
            tags VARCHAR(500) DEFAULT '',
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            reject_reason TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_articles_user_id (user_id),
            INDEX idx_articles_status (status),
            INDEX idx_articles_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

// 自动迁移：确保 article_revisions 文章修订记录表存在
function ensureArticleRevisionsTable() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW TABLES LIKE 'article_revisions'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE article_revisions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            article_id INT NOT NULL,
            user_id INT DEFAULT NULL,
            old_title VARCHAR(255) NOT NULL,
            new_title VARCHAR(255) NOT NULL,
            old_content LONGTEXT NOT NULL,
            new_content LONGTEXT NOT NULL,
            old_tags VARCHAR(500) DEFAULT '',
            new_tags VARCHAR(500) DEFAULT '',
            old_toc_config TEXT,
            new_toc_config TEXT,
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            reject_reason TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ar_article_id (article_id),
            INDEX idx_ar_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

// 自动迁移：确保 articles 表有 toc_config 字段
function ensureArticlesTocColumn() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW COLUMNS FROM articles LIKE 'toc_config'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE articles ADD COLUMN toc_config TEXT DEFAULT NULL AFTER tags");
    }
}

// 自动迁移：确保 articles 表有 has_pending_revision 字段
function ensureArticlesPendingRevisionColumn() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW COLUMNS FROM articles LIKE 'has_pending_revision'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE articles ADD COLUMN has_pending_revision TINYINT(1) DEFAULT 0 AFTER reject_reason");
    }
}

// 自动迁移：确保 messages 站内信表存在
function ensureMessagesTable() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW TABLES LIKE 'messages'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            link_url VARCHAR(1000) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_messages_user_read (user_id, is_read),
            INDEX idx_messages_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

// 自动迁移：确保 view_counts 浏览量统计表存在
function ensureViewCountsTable() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW TABLES LIKE 'view_counts'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE view_counts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            content_type ENUM('article','game','error') NOT NULL,
            content_id INT NOT NULL,
            user_views INT DEFAULT 0,
            guest_views INT DEFAULT 0,
            last_viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_content (content_type, content_id),
            INDEX idx_content_type (content_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

// 自动迁移：确保 view_logs 浏览去重日志表存在
function ensureViewLogsTable() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW TABLES LIKE 'view_logs'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE view_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            content_type ENUM('article','game','error') NOT NULL,
            content_id INT NOT NULL,
            visitor_type ENUM('user','guest') NOT NULL,
            visitor_id VARCHAR(128) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lookup (content_type, content_id, visitor_type, visitor_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

// 自动迁移：确保 admin_logs 操作日志表存在
function ensureAdminLogsTable() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW TABLES LIKE 'admin_logs'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE admin_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            admin_username VARCHAR(30) NOT NULL,
            action VARCHAR(50) NOT NULL,
            target_user_id INT DEFAULT NULL,
            target_username VARCHAR(30) DEFAULT NULL,
            detail TEXT DEFAULT NULL,
            result ENUM('success','fail') DEFAULT 'success',
            ip_address VARCHAR(45) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_logs_admin (admin_id),
            INDEX idx_admin_logs_action (action),
            INDEX idx_admin_logs_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

// 自动迁移：确保 todos 待办表存在
function ensureTodosTable() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW TABLES LIKE 'todos'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE todos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            status ENUM('pending','completed','cancelled') DEFAULT 'pending',
            sort_order INT DEFAULT 0,
            author VARCHAR(100) DEFAULT '',
            completed_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_todos_status (status),
            INDEX idx_todos_sort (sort_order),
            INDEX idx_todos_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM todos LIKE 'completed_at'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE todos ADD COLUMN completed_at DATETIME DEFAULT NULL AFTER author");
    }
}

// 自动迁移：确保 documents 文档管理表存在
function ensureDocumentsTable() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW TABLES LIKE 'documents'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            content LONGTEXT NOT NULL,
            image VARCHAR(500) DEFAULT '',
            link VARCHAR(500) DEFAULT '',
            sort_order INT DEFAULT 0,
            enabled TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_doc_enabled_sort (enabled, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

// 自动迁移：确保 discussions 话题表存在
function ensureDiscussionsTable() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW TABLES LIKE 'discussions'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE discussions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            content LONGTEXT NOT NULL,
            tags VARCHAR(500) DEFAULT '',
            status ENUM('active','deleted') DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_discussions_user_id (user_id),
            INDEX idx_discussions_status (status),
            INDEX idx_discussions_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

// 自动迁移：确保 comments 评论表存在
function ensureCommentsTable() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW TABLES LIKE 'comments'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            content_type ENUM('discussion','error') NOT NULL,
            content_id INT NOT NULL,
            user_id INT NOT NULL,
            content TEXT NOT NULL,
            status ENUM('active','deleted') DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_comments_target (content_type, content_id),
            INDEX idx_comments_user (user_id),
            INDEX idx_comments_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

// 自动迁移：扩展 comments 表的 content_type ENUM 支持 article
function ensureCommentsArticleType() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW COLUMNS FROM comments LIKE 'content_type'");
    $row = $stmt->fetch();
    if ($row && strpos($row['Type'], 'article') === false) {
        $pdo->exec("ALTER TABLE comments MODIFY COLUMN content_type ENUM('discussion','error','article') NOT NULL");
    }
}

// 自动迁移：确保 comments 表有 parent_id 列（回复功能）
function ensureCommentsParentId() {
    $pdo = getDB();
    $cols = $pdo->query("SHOW COLUMNS FROM comments LIKE 'parent_id'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE comments ADD COLUMN parent_id INT DEFAULT NULL AFTER user_id");
        $pdo->exec("ALTER TABLE comments ADD INDEX idx_comments_parent (parent_id)");
    }
}

// 自动迁移：确保 rate_limits 频率限制表存在
function ensureRateLimitsTable() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW TABLES LIKE 'rate_limits'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE rate_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            action_type VARCHAR(30) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_rate_user_action (user_id, action_type, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

// 自动迁移：扩展 view_counts 和 view_logs 表的 content_type ENUM 支持 discussion
function ensureViewCountsDiscussionType() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW COLUMNS FROM view_counts LIKE 'content_type'");
    $row = $stmt->fetch();
    if ($row && strpos($row['Type'], 'discussion') === false) {
        $pdo->exec("ALTER TABLE view_counts MODIFY COLUMN content_type ENUM('article','game','error','discussion') NOT NULL");
    }
    $stmt2 = $pdo->query("SHOW COLUMNS FROM view_logs LIKE 'content_type'");
    $row2 = $stmt2->fetch();
    if ($row2 && strpos($row2['Type'], 'discussion') === false) {
        $pdo->exec("ALTER TABLE view_logs MODIFY COLUMN content_type ENUM('article','game','error','discussion') NOT NULL");
    }
}

// 自动迁移：确保 private_messages 私信表存在
function ensurePrivateMessagesTable() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW TABLES LIKE 'private_messages'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE private_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sender_id INT NOT NULL,
            receiver_id INT NOT NULL,
            content TEXT NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pm_sender (sender_id),
            INDEX idx_pm_receiver (receiver_id, is_read),
            INDEX idx_pm_conversation (sender_id, receiver_id, created_at),
            INDEX idx_pm_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

// 自动迁移：确保 remember_tokens 持久登录令牌表存在
function ensureRememberTokensTable() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW TABLES LIKE 'remember_tokens'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE remember_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            selector VARCHAR(24) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            last_used_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            user_agent VARCHAR(255) DEFAULT '',
            ip_address VARCHAR(45) DEFAULT '',
            revoked_at DATETIME DEFAULT NULL,
            UNIQUE KEY uk_remember_selector (selector),
            INDEX idx_remember_user_id (user_id),
            INDEX idx_remember_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return;
    }

    $columns = [
        'last_used_at' => "ALTER TABLE remember_tokens ADD COLUMN last_used_at DATETIME DEFAULT NULL AFTER expires_at",
        'created_at' => "ALTER TABLE remember_tokens ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER last_used_at",
        'user_agent' => "ALTER TABLE remember_tokens ADD COLUMN user_agent VARCHAR(255) DEFAULT '' AFTER created_at",
        'ip_address' => "ALTER TABLE remember_tokens ADD COLUMN ip_address VARCHAR(45) DEFAULT '' AFTER user_agent",
        'revoked_at' => "ALTER TABLE remember_tokens ADD COLUMN revoked_at DATETIME DEFAULT NULL AFTER ip_address",
    ];

    foreach ($columns as $column => $sql) {
        $colStmt = $pdo->query("SHOW COLUMNS FROM remember_tokens LIKE '" . $column . "'");
        if ($colStmt->rowCount() === 0) {
            $pdo->exec($sql);
        }
    }
}

// 自动迁移：确保 messages 表有 link_url 列（站内信跳转）
function ensureMessagesLinkUrlColumn() {
    $pdo = getDB();
    $cols = $pdo->query("SHOW COLUMNS FROM messages LIKE 'link_url'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE messages ADD COLUMN link_url VARCHAR(1000) DEFAULT NULL AFTER is_read");
    }
}

function ensureAdminGuideMessageLinks() {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE messages SET link_url = '/page/admin-guide' WHERE title = ? AND (link_url IS NULL OR link_url = '')");
    $stmt->execute(['恭喜成为管理员！请阅读管理员须知']);
}

// 自动迁移：确保 site_pages 可编辑页面表存在
function ensureSitePagesTable() {
    $pdo = getDB();
    $stmt = $pdo->query("SHOW TABLES LIKE 'site_pages'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("CREATE TABLE site_pages (
            slug VARCHAR(50) PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            content LONGTEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 插入默认页面
        $defaults = [
            ['about', '关于我们', ''],
            ['legal', '版权及法律声明', "本站为非盈利、个人兴趣分享站点，旨在为 Galgame 玩家提供报错解决方案的交流平台。\n\n站内所有资源均来自网络，仅用于学习交流，不用于商业用途。\n\n如有侵权，请联系站长删除。\n\n禁止利用本站资源进行任何违法活动。\n\n网站仅提供信息展示，不存储、不篡改网盘资源内容。\n\n本声明最终解释权归本站所有。"],
            ['entry-guide', '入站须知', ''],
            ['admin-guide', '管理员须知', ''],
        ];
        $stmt = $pdo->prepare("INSERT INTO site_pages (slug, title, content) VALUES (?, ?, ?)");
        foreach ($defaults as $row) {
            $stmt->execute($row);
        }
    }
}
