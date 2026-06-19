# 后续维护目录说明

本文档用于说明当前 `games` 与 `article_review` 相关后台重构后的目录职责，便于后续继续维护、扩展和迁移。

## 一、整体目标

这次重构的核心目标是把原本集中在单个 PHP 文件中的逻辑拆成：

- 控制器入口
- 查询 / 动作服务
- Twig 页面入口
- 可复用 partial
- 可复用字段块 / 展示块

让后台页面更容易维护，也更适合后续继续做统一组件化。

---

## 二、`games` 相关结构

### 1. 入口层

- `admin/games.php`
  - 负责读取参数
  - 负责加载页面所需数据
  - 负责把上下文传给 Twig

### 2. 页面入口层

- `templates/admin/games.twig`
  - 根据 `action` 决定渲染列表、详情或表单

### 3. 页面级 partial

- `templates/admin/partials/games_list.twig`
- `templates/admin/partials/games_detail.twig`
- `templates/admin/partials/games_form.twig`
- `templates/admin/partials/games_pending_revisions.twig`

### 4. 公共页面组件

- `templates/admin/partials/page_header_actions.twig`
  - 页面标题 + 操作按钮
- `templates/admin/partials/list_filters.twig`
  - 列表筛选栏
- `templates/admin/partials/review_actions.twig`
  - 通用审核按钮：通过 / 驳回
- `templates/admin/partials/reject_modal.twig`
  - 通用驳回弹窗

### 5. `games` 展示面板

- `templates/admin/partials/games_info_panel.twig`
  - 游戏详情展示布局入口
- `templates/admin/partials/games_info_panel_cover.twig`
  - 封面区域
- `templates/admin/partials/games_info_panel_meta.twig`
  - 主信息区域入口
- `templates/admin/partials/games_info_panel_base.twig`
  - 基础字段块
- `templates/admin/partials/games_info_panel_release.twig`
  - 发行信息字段块

### 6. `games` 字段展示 / 表单块

- `templates/admin/partials/games_field_display_image.twig`
- `templates/admin/partials/games_field_display_text.twig`
  - 最底层的通用展示组件

- `templates/admin/partials/games_field_group_basic.twig`
- `templates/admin/partials/games_field_group_meta.twig`
- `templates/admin/partials/games_field_group_cover.twig`
- `templates/admin/partials/games_field_group_submitter.twig`
  - 更高一层的字段组 / 信息组

- `templates/admin/partials/games_fields.twig`
  - 表单字段组合入口

---

## 三、`article_review` 相关结构

### 1. 入口层

- `admin/article_review.php`
  - 现在只保留轻量入口职责：
    - 读取参数
    - 调用上下文装配
    - 交给 Twig 渲染

### 2. 服务聚合层

- `includes/admin_article_review_service.php`
  - 兼容入口，向下委托到 `includes/article_review/service.php`
  - 对控制器提供最终上下文

### 3. 最终目录结构

当前 `article_review` 的最终规范目录为：

```text
includes/
  article_review/
    service.php
    domain/
      queries.php
      article_actions.php
      revision_actions.php
```

### 4. 领域职责说明

#### `includes/article_review/service.php`
- 聚合入口
- 负责组装查询层与动作层
- 输出最终上下文给控制器

#### `includes/article_review/domain/queries.php`
- `loadArticleReviewQueryContext()`
- 只负责查询分发与上下文装配

#### `includes/article_review/domain/article_actions.php`
- 文章审核动作
- 例如：
  - `articleReviewApproveArticle()`
  - `articleReviewRejectArticle()`
  - `articleReviewArticleHandleActions()`

#### `includes/article_review/domain/revision_actions.php`
- 修订审核动作
- 例如：
  - `articleReviewApproveRevision()`
  - `articleReviewRejectRevision()`
  - `articleReviewRevisionHandleActions()`

### 5. 页面级 partial

- `templates/admin/article_review.twig`
  - 根据 `action` 分发到不同 partial

- `templates/admin/partials/article_list.twig`
- `templates/admin/partials/article_detail.twig`
- `templates/admin/partials/article_revisions_list.twig`
- `templates/admin/partials/article_revision_detail.twig`

### 6. 修订 diff 子组件

- `templates/admin/partials/article_revision_diff_fields.twig`
- `templates/admin/partials/article_revision_diff_toc.twig`
- `templates/admin/partials/article_revision_diff_content.twig`

### 7. 公共审核组件

- `templates/admin/partials/review_actions.twig`
- `templates/admin/partials/reject_modal.twig`

---

## 四、文件状态说明

为了避免后续维护混淆，下面标记哪些是**最终文件**，哪些是**旧过渡文件**。

### 1. 最终文件

#### `article_review` 最终文件
- `includes/article_review/service.php`
- `includes/article_review/domain/queries.php`
- `includes/article_review/domain/article_actions.php`
- `includes/article_review/domain/revision_actions.php`
- `includes/admin_article_review_service.php`
  - 兼容入口，但仍保留在仓库中作为旧调用适配层

#### `games` 最终文件
- `admin/games.php`
- `templates/admin/games.twig`
- `templates/admin/partials/games_list.twig`
- `templates/admin/partials/games_detail.twig`
- `templates/admin/partials/games_form.twig`
- `templates/admin/partials/games_pending_revisions.twig`
- `templates/admin/partials/games_info_panel.twig`
- `templates/admin/partials/games_info_panel_cover.twig`
- `templates/admin/partials/games_info_panel_meta.twig`
- `templates/admin/partials/games_info_panel_base.twig`
- `templates/admin/partials/games_info_panel_release.twig`
- `templates/admin/partials/games_field_display_image.twig`
- `templates/admin/partials/games_field_display_text.twig`
- `templates/admin/partials/games_field_group_basic.twig`
- `templates/admin/partials/games_field_group_meta.twig`
- `templates/admin/partials/games_field_group_cover.twig`
- `templates/admin/partials/games_field_group_submitter.twig`
- `templates/admin/partials/games_fields.twig`

### 2. 旧过渡文件

#### `article_review` 过渡文件
- `includes/article_review_query_service.php`
- `includes/article_review_article_query_service.php`
- `includes/article_review_revision_service.php`
- `includes/article_review_list_service.php`
- `includes/article_review_article_actions_service.php`
- `includes/article_review_revision_actions_service.php`
- `includes/article_review_domain_service.php`
- `includes/article_review_domain_queries.php`
- `includes/article_review_domain_article_actions.php`
- `includes/article_review_domain_revision_actions.php`

这些文件的历史作用是把旧逻辑逐步拆到新目录结构里。当前新结构已经落定，后续维护时优先使用 `includes/article_review/service.php` 和 `includes/article_review/domain/*.php`。

#### 说明
- 如果未来确认不再需要兼容旧别名，可以逐步删除这些过渡文件
- 在删除前，先确认没有其他入口仍在引用它们

### 3. 公共组件文件

这些文件属于长期复用组件，不属于过渡文件：

- `templates/admin/partials/page_header_actions.twig`
- `templates/admin/partials/list_filters.twig`
- `templates/admin/partials/review_actions.twig`
- `templates/admin/partials/reject_modal.twig`

---

## 五、维护建议

### 1. 新增类似页面时优先复用公共组件

如果是新的后台审核页，优先考虑复用：

- `page_header_actions.twig`
- `list_filters.twig`
- `review_actions.twig`
- `reject_modal.twig`

### 2. 视图层和逻辑层尽量分离

建议维持以下原则：

- PHP 只负责数据和动作
- Twig 只负责展示
- 公共组件只负责可复用 UI

### 3. 新增字段时优先放进字段组或 display 组件

如果 `games` 后续继续扩展字段，优先按照下面方式扩展：

- 新字段展示放入 `games_field_display_*`
- 字段组合放入 `games_field_group_*`
- 页面级组合放入 `games_info_panel_*`

### 4. `article_review` 后续扩展建议

如果再增加例如：

- 批量审核
- 审核日志
- 撤销审核
- 修订回滚

建议优先放到 `includes/article_review/domain/` 下对应模块，再由 `includes/article_review/service.php` 聚合。

---

## 六、最新目录索引版

下面是当前这批 `users` / `errors` / `article_review` 的最新 partial 目录清单，按当前命名统一整理。

### 1. `users`

#### 页面入口
- `templates/admin/users.twig`

#### 页面组合
- `templates/admin/partials/users_page.twig`
- `templates/admin/partials/users_header.twig`
- `templates/admin/partials/users_message.twig`
- `templates/admin/partials/users_content.twig`

#### 列表
- `templates/admin/partials/users_list.twig`
- `templates/admin/partials/users_table.twig`
- `templates/admin/partials/users_row.twig`

#### 列表单元格
- `templates/admin/partials/users_cell_id.twig`
- `templates/admin/partials/users_cell_username.twig`
- `templates/admin/partials/users_cell_role.twig`
- `templates/admin/partials/users_cell_email.twig`
- `templates/admin/partials/users_cell_status.twig`
- `templates/admin/partials/users_cell_created_at.twig`
- `templates/admin/partials/users_cell_actions.twig`

#### 表单
- `templates/admin/partials/users_form_add.twig`
- `templates/admin/partials/users_form_edit.twig`
- `templates/admin/partials/users_form_reset.twig`

#### 表单字段块
- `templates/admin/partials/users_form_fields.twig`
- `templates/admin/partials/users_form_identity_fields.twig`
- `templates/admin/partials/users_form_permissions.twig`

#### JSON / AJAX
- `includes/users/action_json.php`

---

### 2. `errors`

#### 页面入口
- `templates/admin/errors.twig`

#### 页面组合
- `templates/admin/partials/errors_list_header.twig`
- `templates/admin/partials/errors_list_filter.twig`
- `templates/admin/partials/errors_list_table.twig`
- `templates/admin/partials/errors_detail_info.twig`
- `templates/admin/partials/errors_revisions.twig`
- `templates/admin/partials/errors_revisions_item.twig`

#### 修订区块
- `templates/admin/partials/errors_revision_meta.twig`
- `templates/admin/partials/errors_revision_actions.twig`
- `templates/admin/partials/errors_revision_diff_block.twig`

#### 修订 diff 细分
- `templates/admin/partials/errors_revision_fields_diff.twig`
- `templates/admin/partials/errors_revision_images_diff.twig`

#### 图片 diff 细分
- `templates/admin/partials/revision_diff_images.twig`
- `templates/admin/partials/revision_diff_added_images.twig`
- `templates/admin/partials/revision_diff_removed_images.twig`

#### 字段 diff 细分
- `templates/admin/partials/revision_diff_fields.twig`

#### 后端分层
- `includes/errors/query.php`
- `includes/errors/actions.php`
- `includes/errors/service.php`

---

### 3. `article_review`

#### 页面入口
- `templates/admin/article_review.twig`

#### 页面分发 partial
- `templates/admin/partials/article_list.twig`
- `templates/admin/partials/article_detail.twig`
- `templates/admin/partials/article_revisions_list.twig`
- `templates/admin/partials/article_revision_detail.twig`

#### 修订 diff 组件
- `templates/admin/partials/article_revision_diff_toc.twig`
- `templates/admin/partials/article_revision_diff_fields.twig`
- `templates/admin/partials/article_revision_diff_content.twig`

#### 后端分层
- `includes/article_review/service.php`
- `includes/article_review/domain/queries.php`
- `includes/article_review/domain/article_actions.php`
- `includes/article_review/domain/revision_actions.php`

#### 兼容入口
- `includes/admin_article_review_service.php`

---

## 七、当前推荐结构示意

```text
admin/
  games.php
  article_review.php
  errors.php
  users.php
  solutions.php
  migrate_error_solutions.php
  game_review.php
  discussion.php

includes/
  admin_article_review_service.php
  article_review/
    service.php
    domain/
      queries.php
      article_actions.php
      revision_actions.php
  errors/
    query.php
    actions.php
    service.php
  users/
    ajax.php
    query.php
    forms.php
    service.php
  solutions/
    query.php
    actions.php
    service.php
  error_solutions/
    query.php
    service.php
  discussion/
    query.php
    service.php

templates/admin/
  games.twig
  article_review.twig
  errors.twig
  users.twig
  solutions.twig
  migrate_error_solutions.twig
  partials/
    page_header_actions.twig
    list_filters.twig
    review_actions.twig
    reject_modal.twig

    games_*.twig
    article_*.twig
    errors_*.twig
    users_*.twig
    solutions_*.twig

templates/front/
  error_solutions.twig
  discussion.twig
  partials/
    error_solutions_*.twig
    discussion_*.twig
```

---

## 八、兼容跳转入口

以下文件当前属于**兼容跳转入口**，而不是业务页面：

- `admin/game_review.php`
  - 仅负责 301 跳转到 `admin/games.php`
  - 历史原因保留

---

## 九、后续目录维护说明（补充）

### 1. 目前已经完成或基本完成统一的页面
以下页面已经完成了 Twig 化、入口薄化、逻辑下沉与 partial 收口，后续维护优先沿用现有分层，不建议再回退为单文件拼接：

- `admin/ads.php`
- `admin/categories.php`
- `admin/discussion.php`
- `admin/private_chat.php`
- `admin/url_whitelist.php`
- `admin/user_manage.php`
- `admin/users.php`
- `admin/games.php`
- `admin/documents.php`
- `admin/pages.php`
- `admin/errors.php`
- `admin/solutions.php`
- `admin/article_review.php`
- `admin/sensitive_logs.php`
- `admin/admin_settings.php`
- `admin/smtp_settings.php`
- `admin/site_settings.php`
- `admin/index.php`

### 2. 仍然属于混合页，但已经不必强行回到旧结构
这些页面更偏前台展示或前台/后台混合页；如果后续要迁移，建议新建前台 layout 和前台 partial 体系，而不是继续塞进管理后台模板：

- `admin/game.php`
- `admin/article.php`
- `admin/error_detail.php`
- `admin/login.php`（可迁移，但优先级较低）

### 3. 不建议继续 Twig 化的工具/维护脚本
这些文件更适合作为维护脚本、迁移脚本或兼容跳转入口保留，没必要再拆成页面模板：

- `admin/game_review.php`（301 跳转）
- `admin/migrate_error_solutions.php`
- `admin/solution_stats_fix.php`
- `admin/drop_old_solution_screenshots.php`
- `admin/ensure_solution_screenshots.php`
- `admin/migrate_covers.php`
- `admin/migration_status.php`
- `admin/media_cleanup.php`
- `admin/twig_test.php`
- `admin/logout.php`

### 4. 后续维护建议
#### 统一命名
后续新增页面建议优先采用以下命名规律：

- 页面入口：`templates/admin/<module>.twig`
- 页面组合：`templates/admin/partials/<module>_page.twig`
- 头部区域：`templates/admin/partials/<module>_header.twig`
- 提示区域：`templates/admin/partials/<module>_message.twig`
- 列表区域：`templates/admin/partials/<module>_list.twig`
- 表单区域：`templates/admin/partials/<module>_form.twig`
- 弹窗区域：`templates/admin/partials/<module>_modals.twig`

#### 资源加载
建议继续沿用以下原则：

- 基础 CSS / JS 只放在 layout 中
- 页面专属 CSS 通过 `extra_stylesheets` 注入
- 页面专属脚本使用独立文件，通过 `extra_scripts_html` 或页面 partial 引入
- 不再在单个 PHP 入口里内联大量脚本

#### 公共组件复用
页面中可复用的区块，建议优先提取为公共 partial，例如：

- 页面头部动作条
- 列表筛选条
- 状态标签
- 审核操作按钮组
- 通用确认弹窗
- 统计卡片

### 5. 前台页面迁移建议
前台页面如果后续要继续统一，建议先补前台 layout，再逐步迁移到 `templates/front/`：

- `game.php`
- `article.php`
- `error_detail.php`

前台建议结构：

- `templates/front/layout.twig`
- `templates/front/<page>.twig`
- `templates/front/partials/<page>_*.twig`

### 6. 当前维护准则
- 新页面优先走 Twig
- 旧页面先薄入口，再下沉 service/actions/query
- 能复用的组件先抽 partial
- 工具脚本保持脚本化，不强行页面化
- 只做转发的兼容壳，确认无引用后再删除

---

## 十、日常维护清单

后续新增或修改后台页面时，建议按下面顺序检查：

1. 先看是否已有对应的 `query / actions / service` 分层可复用。
2. 页面是否只负责装配 `content_html`，不再直接拼接大段 HTML。
3. 是否能复用现有 partial，例如 `page_header_actions`、`list_filters`、`review_actions`、`reject_modal`。
4. 新的交互逻辑是否应抽到独立 JS 文件，而不是继续写进模板里。
5. 是否还有旧的兼容壳、历史入口或重复命名文件需要清理。
6. 前台混合页是否应该迁移到 `templates/front/` 体系，而不是继续放进后台目录。

## 十一、备注

当前项目里还有其他模块也采用类似思路，例如 `solutions`、`site_settings`、`documents` 等。后续如果要继续统一整个后台，建议按同样分层方式逐步迁移。
