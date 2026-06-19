(function () {
    'use strict';

    if (!window.fetch) {
        return;
    }

    var intervalMs = 60000;
    var endpoint = '/api/activity_ping.php';
    var timer = null;

    function sendPing() {
        var headers = { 'Content-Type': 'application/json' };
        if (window.__CSRF_TOKEN__) {
            headers['X-CSRF-Token'] = window.__CSRF_TOKEN__;
        }
        return fetch(endpoint, {
            method: 'POST',
            headers: headers,
            body: '{}',
            credentials: 'same-origin'
        });
    }

    function schedule() {
        if (timer) {
            clearInterval(timer);
        }
        timer = setInterval(function () {
            if (!document.hidden) {
                sendPing().catch(function () {});
            }
        }, intervalMs);
    }

    var activityEvents = ['click', 'keydown', 'scroll', 'touchstart'];
    var lastEventAt = 0;
    function onActivity() {
        var now = Date.now();
        if (now - lastEventAt < 30000) {
            return;
        }
        lastEventAt = now;
        sendPing().catch(function () {});
    }

    function start() {
        for (var i = 0; i < activityEvents.length; i++) {
            window.addEventListener(activityEvents[i], onActivity, { passive: true });
        }

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                sendPing().catch(function () {});
            }
        });

        sendPing().then(function (res) {
            if (!res || !res.ok) {
                return;
            }
            schedule();
        }).catch(function () {});
    }

    start();
})();
