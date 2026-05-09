# Galgame 报错解决百科网站

一个专门收集和分享 Galgame 报错解决方案的百科网站，使用原生 PHP + MySQL 开发。

## 功能特性

### 前台功能
- **首页**：展示搜索框、最新游戏、常见报错分类
- **全站搜索**：支持搜索游戏名、报错内容、VNDB 编号
- **游戏详情页**：展示游戏信息 + 该游戏所有已审核报错
- **用户提交报错**：可填写游戏ID、报错分类、标题、现象、系统、汉化补丁、解决方案、上传截图
- **审核机制**：所有用户提交的报错必须经过管理员审核才能显示

### 后台功能
- **独立后台登录**：安全的密码验证机制
- **控制台**：统计数据展示、最新待审核报错、快捷操作
- **游戏管理**：查看所有游戏、添加游戏、删除游戏、通过 VNDB 编号自动抓取游戏信息
- **报错分类管理**：可自由添加、编辑、删除报错类型
- **报错管理**：查看所有用户提交的报错、审核通过/删除、查看报错截图

### 核心特色功能
- **VNDB 集成**：用户提交报错时，如果网站没有该游戏，可通过 VNDB ID 自动创建游戏
- **一键抓取**：后台可一键抓取 VNDB 游戏信息自动填充
- **多图上传**：支持上传多张报错截图
- **响应式设计**：支持手机/电脑访问
- **安全可靠**：代码干净、注释清晰、可直接上线运行

## 技术栈

- **后端**：原生 PHP 7.4+
- **数据库**：MySQL 5.7+ / MariaDB 10.2+
- **前端**：HTML5 + CSS3 + JavaScript
- **样式**：简约工具站风格，无渐变、无玻璃态、无大圆角

## 界面风格

- 灰白主色 + 淡蓝按钮
- 卡片干净、无边框特效
- 无多余动画、无虚化背景
- 字体清晰易读
- 接近知乎/掘金/工具文档站风格

## 目录结构

```
www.galerror.com/
├── admin/                  # 后台管理目录
│   ├── index.php          # 控制台
│   ├── login.php          # 登录页面
│   ├── logout.php         # 退出登录
│   ├── games.php          # 游戏管理
│   ├── categories.php     # 分类管理
│   └── errors.php         # 报错管理
├── assets/                 # 静态资源
│   └── css/
│       └── style.css      # 主样式文件
├── includes/              # 公共文件
│   └── config.php         # 配置文件
├── uploads/               # 上传文件目录
├── database.sql          # 数据库结构
├── index.php            # 首页
├── search.php           # 搜索页面
├── game.php            # 游戏详情页
├── submit.php          # 提交报错页面
└── README.md           # 项目说明文档
```

## 安装部署

### 1. 环境要求

- PHP 7.4 或更高版本
- MySQL 5.7+ 或 MariaDB 10.2+
- Web 服务器（Apache/Nginx）
- PHP 扩展：PDO、PDO_MySQL、GD、JSON、cURL

### 2. 数据库配置

1. 创建数据库和用户：
```sql
CREATE DATABASE galgame_errors CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'galgame'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON galgame_errors.* TO 'galgame'@'localhost';
FLUSH PRIVILEGES;
```

2. 导入数据库结构：
```bash
mysql -u galgame -p galgame_errors < database.sql
```

### 3. 配置文件

修改 `includes/config.php` 中的数据库配置：

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'galgame_errors');
define('DB_USER', 'galgame');
define('DB_PASS', 'your_password');
```

### 4. 目录权限

确保以下目录可写：
```bash
chmod 755 uploads/
```

### 5. Web 服务器配置

#### Apache 配置示例
```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /path/to/www.galerror.com
    
    <Directory /path/to/www.galerror.com>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

#### Nginx 配置示例
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/www.galerror.com;
    index index.php index.html;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## 默认账户

- **管理员账户**：admin
- **密码**：admin123

⚠️ **安全提醒**：部署后请立即修改默认密码！

## 使用说明

### 用户使用流程

1. **搜索报错**：在首页或搜索页面输入游戏名、报错内容或 VNDB 编号
2. **浏览游戏**：点击游戏查看该游戏的所有报错解决方案
3. **提交报错**：如果遇到新的报错，可以通过提交页面分享解决方案
4. **VNDB 集成**：提交时输入 VNDB ID 可自动获取游戏信息

### 管理员使用流程

1. **登录后台**：访问 `/admin/` 目录，使用管理员账户登录
2. **审核报错**：在报错管理页面审核用户提交的内容
3. **管理游戏**：通过 VNDB ID 添加游戏，或手动创建游戏条目
4. **分类管理**：自定义报错分类，方便用户分类查找

## API 接口

### VNDB API

系统集成了 VNDB API，支持通过 VNDB ID（如 v12345）自动获取游戏信息：

- 游戏标题
- 日文名
- 开发商
- 发售日
- 封面图
- 平台

## 安全特性

- **SQL 注入防护**：使用 PDO 预处理语句
- **XSS 防护**：所有输出都经过 htmlspecialchars 处理
- **CSRF 防护**：重要操作需要确认
- **文件上传安全**：限制文件类型和大小
- **会话安全**：安全的登录验证机制

## 性能优化

- **数据库索引**：关键字段建立索引
- **分页查询**：避免一次性加载大量数据
- **图片优化**：支持外部图片链接
- **缓存机制**：可扩展 Redis 缓存

## 扩展功能

系统设计支持以下扩展：

1. **用户系统**：可添加用户注册、登录功能
2. **评论系统**：可为解决方案添加评论
3. **点赞功能**：用户可为有用的解决方案点赞
4. **标签系统**：为游戏和报错添加标签
5. **API 接口**：提供 RESTful API 供第三方调用

## 故障排除

### 常见问题

1. **数据库连接失败**
   - 检查数据库配置信息
   - 确认数据库服务正在运行
   - 检查用户权限

2. **文件上传失败**
   - 检查 uploads 目录权限
   - 确认 PHP 上传配置
   - 检查磁盘空间

3. **VNDB API 调用失败**
   - 检查网络连接
   - 确认 cURL 扩展已启用
   - 检查 VNDB 服务状态

### 日志查看

- PHP 错误日志：`/var/log/php_errors.log`
- Web 服务器日志：根据服务器配置查看

## 更新说明

### v1.0.0 (2026-03-22)
- 初始版本发布
- 完整的前后台功能
- VNDB 集成
- 响应式设计

## 技术支持

如遇到问题，请检查：

1. 服务器环境是否符合要求
2. 数据库配置是否正确
3. 目录权限是否设置正确
4. PHP 错误日志中的具体错误信息

## 开源协议

本项目采用 MIT 协议开源，可自由使用和修改。

---

**注意**：本网站仅用于技术交流和分享，请遵守相关法律法规，尊重知识产权。
