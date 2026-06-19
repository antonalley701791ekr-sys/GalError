/**
 * article-comments.js —— 文章详情页评论交互
 * ------------------------------------------------------------------
 * 评论逻辑已统一抽到 comment-core.js（window.GalCommentCore），本文件只负责：
 *   1) 用“文章页”配置初始化评论核心（行为与旧版一致）；
 *   2) 文章专属的目录(TOC)导航逻辑（与评论无关，保留在此）。
 * 加载顺序：必须在 comment-core.js 之后加载。
 *
 * 契约（与 api/comment.php 对齐，详见 docs/评论系统梳理.md）：
 *   content_type='article'；端点 POST /api/comment.php；导出全局
 *   openCommentModal/closeCommentModal/switchCommentEditorMode/submitComment/
 *   cancelReply/replyToComment/editComment/deleteComment/deleteDiscussion。
 */
(function () {
    'use strict';
    function byId(id) { return document.getElementById(id); }

    // —— 评论核心（文章页配置）——
    var commentApi = (window.GalCommentCore && window.GalCommentCore.create) ? window.GalCommentCore.create({
        tag: 'article',
        contentType: 'article',
        exposeGlobals: true,
        getContentId: function () { var el = byId('view-counter-config'); return el ? parseInt(el.getAttribute('data-id') || '0', 10) : 0; }
    }) : null;

    // —— 文章目录 (TOC) 导航：文章专属，与评论无关 ——
    function bindToc() {
        var tocNav = byId('tocNav');
        if (!tocNav) return;
        tocNav.addEventListener('click', onTocClick);
        window.addEventListener('scroll', updateTocActive, { passive: true });
        updateTocActive();

        var tocToggle = byId('tocToggle');
        var tocCard = byId('tocCard');
        if (tocToggle && tocCard && window.innerWidth <= 768) {
            tocCard.classList.add('collapsed');
            tocToggle.addEventListener('click', function () { tocCard.classList.toggle('collapsed'); });
        }
    }

    function onTocClick(e) {
        var link = e.target.closest('a');
        if (!link) return;
        e.preventDefault();
        var targetId = link.getAttribute('href').substring(1);
        var target = document.getElementById(targetId);
        if (!target) return;
        var navHeight = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--nav-height')) || 64;
        var top = target.getBoundingClientRect().top + window.pageYOffset - navHeight - 20;
        window.scrollTo({ top: top, behavior: 'smooth' });
    }

    function updateTocActive() {
        var tocLinks = document.querySelectorAll('#tocNav a');
        if (!tocLinks.length) return;
        var headings = [];
        tocLinks.forEach(function (link) {
            var id = link.getAttribute('href').substring(1);
            var el = document.getElementById(id);
            if (el) headings.push({ el: el, link: link });
        });
        if (!headings.length) return;
        var navHeight = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--nav-height')) || 64;
        var scrollTop = window.pageYOffset;
        var threshold = navHeight + 40;
        var currentIndex = -1;
        for (var i = headings.length - 1; i >= 0; i--) {
            if (headings[i].el.getBoundingClientRect().top + window.pageYOffset - threshold <= scrollTop) { currentIndex = i; break; }
        }
        tocLinks.forEach(function (link) { link.classList.remove('active'); });
        if (currentIndex >= 0) {
            headings[currentIndex].link.classList.add('active');
            var tocCard = byId('tocCard');
            var activeLink = headings[currentIndex].link;
            if (tocCard && activeLink) {
                var linkTop = activeLink.offsetTop - tocCard.offsetTop;
                var cardScroll = tocCard.scrollTop;
                var cardHeight = tocCard.clientHeight;
                if (linkTop < cardScroll + 40 || linkTop > cardScroll + cardHeight - 40) tocCard.scrollTop = linkTop - cardHeight / 3;
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (commentApi) commentApi.init();
        bindToc();
    });
})();
