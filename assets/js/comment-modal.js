/**
 * comment-modal.js —— 报错详情页评论交互
 * ------------------------------------------------------------------
 * 评论逻辑已统一到 comment-core.js（window.GalCommentCore）。本文件只负责：
 *   用“报错页”配置初始化核心，并接入锚点高亮。
 * 加载顺序：必须在 comment-core.js 之后加载。
 *
 * 报错页特点（已在核心中兼容）：
 *   - content_type='error'、content_id、csrfToken 取自 window.GalCommentConfig；
 *   - 提交后“就地插入 DOM + 幂等键”——核心已升级为此架构（三套统一）；
 *   - 按钮事件委托由核心负责；GalCommentInteractions 仅用于锚点高亮
 *     （调用 init({}) 不传 onReply/onEdit/onDelete，避免与核心委托双触发）。
 */
(function () {
    'use strict';
    var cfg = window.GalCommentConfig || {};

    var commentApi = (window.GalCommentCore && window.GalCommentCore.create) ? window.GalCommentCore.create({
        tag: 'error',
        contentType: cfg.contentType || 'error',
        exposeGlobals: true,
        getContentId: function () { return parseInt((window.GalCommentConfig && window.GalCommentConfig.contentId) || 0, 10) || 0; }
    }) : null;

    document.addEventListener('DOMContentLoaded', function () {
        if (commentApi) commentApi.init();
        // 仅用 GalCommentInteractions 的锚点高亮 + 初始 hash 跳转；按钮委托交给核心（不传回调）
        if (window.GalCommentInteractions && typeof window.GalCommentInteractions.init === 'function') {
            window.GalCommentInteractions.init({});
        }
    });
})();
