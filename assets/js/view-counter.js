/**
 * 浏览量统计前端脚本
 * - 页面加载完成后自动触发统计
 * - 同一会话5分钟内不重复计数（sessionStorage）
 * - 使用简易浏览器指纹进行访客去重
 */
(function () {
    'use strict';

    // 获取页面上的统计配置
    var el = document.getElementById('view-counter-config');
    if (!el) return;

    var contentType = el.getAttribute('data-type');
    var contentId = el.getAttribute('data-id');
    if (!contentType || !contentId) return;

    // 5分钟会话去重
    var cacheKey = 'vc_' + contentType + '_' + contentId;
    var lastTime = sessionStorage.getItem(cacheKey);
    if (lastTime && (Date.now() - parseInt(lastTime, 10)) < 300000) {
        // 5分钟内已统计，不再发送请求
        return;
    }

    // 简易浏览器指纹
    function getFingerprint() {
        var canvas = document.createElement('canvas');
        var ctx = canvas.getContext('2d');
        ctx.textBaseline = 'top';
        ctx.font = '14px Arial';
        ctx.fillText('fp', 2, 2);
        var canvasData = canvas.toDataURL();
        var raw = navigator.userAgent + '|' + screen.width + 'x' + screen.height + '|' +
            (navigator.language || '') + '|' + new Date().getTimezoneOffset() + '|' + canvasData;
        // 简单hash
        var hash = 0;
        for (var i = 0; i < raw.length; i++) {
            hash = ((hash << 5) - hash) + raw.charCodeAt(i);
            hash |= 0;
        }
        return Math.abs(hash).toString(36);
    }

    // 异步发送统计请求
    var data = JSON.stringify({
        content_type: contentType,
        content_id: parseInt(contentId, 10),
        fingerprint: getFingerprint()
    });

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/api/record_view.php', true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                var resp = JSON.parse(xhr.responseText);
                if (resp.success) {
                    // 标记已统计
                    sessionStorage.setItem(cacheKey, Date.now().toString());
                    // 更新页面上的浏览量显示
                    var userEl = document.getElementById('view-count-user');
                    var guestEl = document.getElementById('view-count-guest');
                    var totalEl = document.querySelectorAll('.view-count-total');
                    if (userEl) userEl.textContent = resp.user_views;
                    if (guestEl) guestEl.textContent = resp.guest_views;
                    totalEl.forEach(function (e) {
                        e.textContent = resp.total_views;
                    });
                }
            } catch (e) { }
        }
    };
    xhr.send(data);
})();
