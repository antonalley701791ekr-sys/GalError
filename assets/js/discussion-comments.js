/**
 * discussion-comments.js —— 讨论详情页评论交互
 * ------------------------------------------------------------------
 * 评论逻辑已统一到 comment-core.js（window.GalCommentCore）。本文件只负责：
 *   用“讨论页”配置初始化核心，并补讨论页特有的全局别名。
 * 加载顺序：必须在 comment-core.js 之后加载。
 *
 * 讨论页与文章页的差异（已在核心中以 config 兼容）：
 *   - content_type='discussion'；
 *   - multiImageUpload=true（讨论页支持一次多选上传，核心据此走多图通道）；
 *   - 提交按钮模板里用 inline onclick="discussionSubmitComment()" → 此处把它指向核心 submit；
 *     （核心 submit 已带重入守卫，inline + #commentSubmitBtn 委托双触发也不会重复提交）；
 *   - 删除话题走 /discussion_delete.php（核心 deleteDiscussion 已与此一致）；
 *   - 删除评论 inline 用 window.galDeleteComment（核心已导出该别名）。
 */
(function () {
    'use strict';
    function byId(id) { return document.getElementById(id); }

    var commentApi = (window.GalCommentCore && window.GalCommentCore.create) ? window.GalCommentCore.create({
        tag: 'discussion',
        contentType: 'discussion',
        exposeGlobals: true,
        multiImageUpload: true,
        getContentId: function () { var el = byId('view-counter-config'); return el ? parseInt(el.getAttribute('data-id') || '0', 10) : 0; }
    }) : null;

    if (commentApi) {
        // 讨论页模态框提交按钮的 inline onclick 用 discussionSubmitComment()
        window.discussionSubmitComment = commentApi.submitComment;
    }

    // 兼容：模板里评论按钮有 GalCommentInteractions 回退分支（主路径走 window.replyToComment，正常不触发）
    window.GalCommentInteractions = window.GalCommentInteractions || {};
    if (typeof window.GalCommentInteractions.init !== 'function') window.GalCommentInteractions.init = function () {};

    document.addEventListener('DOMContentLoaded', function () {
        if (commentApi) commentApi.init();
    });
})();
