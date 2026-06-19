(function() {
    var activeCard = null;
    var activeTrigger = null;
    var hoverTimer = null;
    var leaveTimer = null;
    var pendingRequestId = 0;
    var cache = {};
    var pinnedByClick = false;

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            value = '';
        }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getUserIdFromHref(href) {
        if (!href) return 0;
        try {
            var url = new URL(href, window.location.origin);
            if (url.pathname !== '/profile' && url.pathname !== '/profile.php') return 0;
            return parseInt(url.searchParams.get('user_id') || '0', 10) || 0;
        } catch (e) {
            return 0;
        }
    }

    function getTriggerUserId(trigger) {
        var direct = parseInt(trigger.getAttribute('data-user-id') || '0', 10) || 0;
        if (direct > 0) return direct;
        if (trigger.matches('a[href]')) return getUserIdFromHref(trigger.getAttribute('href'));
        var link = trigger.querySelector && trigger.querySelector('a[href*="user_id="]');
        if (link) return getUserIdFromHref(link.getAttribute('href'));
        return 0;
    }

    function getTriggerName(trigger) {
        return trigger.getAttribute('data-username') || trigger.textContent.trim() || '用户';
    }

    function getTriggerAvatar(trigger) {
        var src = trigger.getAttribute('data-avatar') || '';
        if (src) return src;
        if (trigger.matches('img')) return trigger.getAttribute('src') || '';
        var img = trigger.querySelector && trigger.querySelector('img');
        return img ? (img.getAttribute('src') || '') : '';
    }

    function clearTimers() {
        if (hoverTimer) {
            clearTimeout(hoverTimer);
            hoverTimer = null;
        }
        if (leaveTimer) {
            clearTimeout(leaveTimer);
            leaveTimer = null;
        }
    }

    function closeCard() {
        clearTimers();
        pinnedByClick = false;
        if (activeCard && activeCard.parentNode) {
            activeCard.parentNode.removeChild(activeCard);
        }
        activeCard = null;
        activeTrigger = null;
    }

    function positionCard(card, trigger) {
        var rect = trigger.getBoundingClientRect();
        var cardRect = card.getBoundingClientRect();
        var gap = 12;
        var left = rect.left + window.scrollX;
        var top = rect.bottom + window.scrollY + gap;

        if (left + cardRect.width > window.scrollX + window.innerWidth - 12) {
            left = window.scrollX + window.innerWidth - cardRect.width - 12;
        }
        if (left < window.scrollX + 12) left = window.scrollX + 12;

        if (top + cardRect.height > window.scrollY + window.innerHeight - 12) {
            top = rect.top + window.scrollY - cardRect.height - gap;
        }
        if (top < window.scrollY + 12) top = window.scrollY + 12;

        card.style.left = left + 'px';
        card.style.top = top + 'px';
    }

    function statValue(value) {
        if (value === null || value === undefined || value === '') {
            return 0;
        }
        var num = Number(value);
        if (Number.isNaN(num)) {
            return 0;
        }
        return num;
    }

    function buildStats(user) {
        var stats = user.stats || {};
        var items = [
            { label: '游戏', value: statValue(stats.games_count) },
            { label: '报错', value: statValue(stats.errors_count) },
            { label: '方案', value: statValue(stats.solutions_count) },
            { label: '修正', value: statValue(stats.solution_fix_count) },
            { label: '文章', value: statValue(stats.articles_count) },
            { label: '话题', value: statValue(stats.discussions_count) },
            { label: '评论', value: statValue(stats.comments_count) }
        ];

        return items.map(function(item) {
            return '<div class="user-pop-stat"><span class="user-pop-stat-value">' + escapeHtml(item.value) + '</span><span class="user-pop-stat-label">' + escapeHtml(item.label) + '</span></div>';
        }).join('');
    }

    function normalizeUser(user, fallback) {
        var base = fallback || {};
        var rawStats = (user && user.stats) || {};
        var fallbackStats = (base && base.stats) || {};
        return {
            id: (user && user.id) || base.id || 0,
            username: (user && user.username) || base.username || '用户',
            avatar: (user && user.avatar) || base.avatar || '',
            role_label: (user && user.role_label) || base.role_label || '',
            profile_url: (user && user.profile_url) || base.profile_url || ('/profile?user_id=' + ((user && user.id) || base.id || 0)),
            message_url: (user && user.message_url) || base.message_url || ('/private_chat.php?user_id=' + ((user && user.id) || base.id || 0)),
            can_message: typeof (user && user.can_message) === 'boolean' ? user.can_message : !!base.can_message,
            stats: {
                games_count: statValue(rawStats.games_count !== undefined ? rawStats.games_count : fallbackStats.games_count),
                errors_count: statValue(rawStats.errors_count !== undefined ? rawStats.errors_count : fallbackStats.errors_count),
                solutions_count: statValue(rawStats.solutions_count !== undefined ? rawStats.solutions_count : fallbackStats.solutions_count),
                articles_count: statValue(rawStats.articles_count !== undefined ? rawStats.articles_count : fallbackStats.articles_count),
                solution_fix_count: statValue(rawStats.solution_fix_count !== undefined ? rawStats.solution_fix_count : fallbackStats.solution_fix_count),
                discussions_count: statValue(rawStats.discussions_count !== undefined ? rawStats.discussions_count : fallbackStats.discussions_count),
                comments_count: statValue(rawStats.comments_count !== undefined ? rawStats.comments_count : fallbackStats.comments_count)
            }
        };
    }

    function createCard(user) {
        var card = document.createElement('div');
        card.className = 'user-pop-card';
        var avatarHtml = user.avatar
            ? '<img src="' + escapeHtml(user.avatar) + '" class="user-pop-avatar" alt="">'
            : '<div class="user-pop-avatar fallback">' + escapeHtml((user.username || '用').slice(0, 1)) + '</div>';
        var messageButton = user.can_message
            ? '<a href="' + escapeHtml(user.message_url || ('/private_chat.php?user_id=' + (user.id || 0))) + '" class="btn btn-secondary btn-sm">发私信</a>'
            : '';

        card.innerHTML =
            '<div class="user-pop-head">' + avatarHtml +
                '<div class="user-pop-main">' +
                    '<div class="user-pop-name">' + escapeHtml(user.username || '用户') + '</div>' +
                    '<div class="user-pop-meta">' +
                        (user.role_label ? '<span class="user-pop-role">' + escapeHtml(user.role_label) + '</span>' : '') +
                        '<span class="user-pop-id">ID：' + escapeHtml(user.id || 0) + '</span>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div class="user-pop-stats">' + buildStats(user) + '</div>' +
            '<div class="user-pop-actions">' +
                '<a href="' + escapeHtml(user.profile_url || ('/profile?user_id=' + (user.id || 0))) + '" class="btn btn-sm">个人主页</a>' +
                messageButton +
            '</div>';
        return card;
    }

    function fetchUser(userId, fallback, callback) {
        if (cache[userId]) {
            callback(cache[userId]);
            return;
        }

        var requestId = ++pendingRequestId;
        fetch('/profile.php?card_user_id=' + encodeURIComponent(userId), {
            credentials: 'same-origin'
        })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (requestId !== pendingRequestId) return;
                if (!data || !data.success || !data.user) return;
                cache[userId] = data.user;
                callback(data.user);
            })
            .catch(function() {
                if (requestId !== pendingRequestId) return;
                callback(fallback);
            });
    }

    function showCard(trigger, options) {
        var userId = getTriggerUserId(trigger);
        if (!userId) return;
        options = options || {};

        var fallback = {
            id: userId,
            username: getTriggerName(trigger),
            avatar: getTriggerAvatar(trigger),
            role_label: trigger.getAttribute('data-role-label') || '',
            profile_url: '/profile?user_id=' + userId,
            message_url: '/private_chat.php?user_id=' + userId,
            can_message: true,
            stats: {
                games_count: 0,
                errors_count: 0,
                solutions_count: 0,
                articles_count: 0,
                discussions_count: 0,
                solution_fix_count: 0,
                comments_count: 0
            }
        };

        fetchUser(userId, fallback, function(user) {
            if (activeTrigger !== trigger) return;
            if (activeCard && activeCard.parentNode) {
                activeCard.parentNode.removeChild(activeCard);
            }
            activeCard = createCard(normalizeUser(user, fallback));
            document.body.appendChild(activeCard);
            positionCard(activeCard, trigger);
            if (options.pin) {
                pinnedByClick = true;
            }
            activeCard.addEventListener('mouseenter', function() {
                if (leaveTimer) {
                    clearTimeout(leaveTimer);
                    leaveTimer = null;
                }
            });
            activeCard.addEventListener('mouseleave', function() {
                if (!pinnedByClick) {
                    scheduleClose();
                }
            });
        });
    }

    function scheduleOpen(trigger) {
        clearTimers();
        pinnedByClick = false;
        activeTrigger = trigger;
        hoverTimer = setTimeout(function() {
            hoverTimer = null;
            showCard(trigger, { pin: false });
        }, 500);
    }

    function scheduleClose() {
        if (pinnedByClick) return;
        if (leaveTimer) clearTimeout(leaveTimer);
        leaveTimer = setTimeout(function() {
            closeCard();
        }, 160);
    }

    function findClosestElement(target, selector) {
        if (!target) return null;
        var el = target.nodeType === 1 ? target : target.parentElement;
        if (!el || !el.closest) return null;
        return el.closest(selector);
    }

    function findTrigger(target) {
        var el = findClosestElement(target, '.js-user-card, .detail-author-link[href*="user_id="], .comment-user-link[href*="user_id="]');
        if (el) return el;
        if (target && target.classList && (target.classList.contains('article-author-avatar') || target.classList.contains('comment-author-avatar'))) {
            var parent = findClosestElement(target, '.article-author, .comment-author-info, .game-detail-meta');
            if (parent) {
                return parent.querySelector('.js-user-card, .detail-author-link[href*="user_id="], .comment-user-link[href*="user_id="]');
            }
        }
        return null;
    }

    document.addEventListener('mouseenter', function(e) {
        var trigger = findTrigger(e.target);
        if (!trigger) return;
        scheduleOpen(trigger);
    }, true);

    document.addEventListener('mouseleave', function(e) {
        var trigger = findTrigger(e.target);
        if (!trigger) return;
        scheduleClose();
    }, true);

    document.addEventListener('click', function(e) {
        var trigger = findTrigger(e.target);
        if (trigger) {
            e.preventDefault();
            clearTimers();
            if (activeTrigger === trigger && activeCard && pinnedByClick) {
                closeCard();
                return;
            }
            activeTrigger = trigger;
            showCard(trigger, { pin: true });
            return;
        }
        if (activeCard && !activeCard.contains(e.target)) {
            closeCard();
        }
    }, true);

    window.addEventListener('resize', closeCard);
    window.addEventListener('scroll', closeCard, true);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeCard();
    });
})();
