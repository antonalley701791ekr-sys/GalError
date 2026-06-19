(function(window) {
    'use strict';

    function highlightCommentByHash(hash, options) {
        options = options || {};
        if (!hash || hash.indexOf('#comment-') !== 0) return;
        var target = document.querySelector(hash);
        if (!target) return;

        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        target.style.backgroundColor = 'rgba(167, 139, 250, 0.10)';
        target.style.boxShadow = '0 0 0 1px rgba(167, 139, 250, 0.30), 0 0 0 8px rgba(167, 139, 250, 0.10)';
        target.style.borderColor = 'rgba(167, 139, 250, 0.38)';
        target.style.transform = 'translateY(-1px)';

        if (target._flashTimer) {
            clearTimeout(target._flashTimer);
        }
        target._flashTimer = setTimeout(function() {
            target.style.backgroundColor = '';
            target.style.boxShadow = '';
            target.style.borderColor = '';
            target.style.transform = '';
        }, 1800);

        if (options.clearHash && window.location.hash === hash && window.history && window.history.replaceState) {
            window.setTimeout(function() {
                window.history.replaceState(null, document.title, window.location.pathname + window.location.search);
            }, 50);
        }
    }

    function init(options) {
        options = options || {};
        var onReply = typeof options.onReply === 'function' ? options.onReply : null;
        var onEdit = typeof options.onEdit === 'function' ? options.onEdit : null;
        var onDelete = typeof options.onDelete === 'function' ? options.onDelete : null;

        function consumeInitialCommentHash() {
            highlightCommentByHash(window.location.hash, { clearHash: true });
        }

        window.addEventListener('hashchange', function() {
            highlightCommentByHash(window.location.hash);
        });

        document.addEventListener('click', function(event) {
            var replyButton = event.target.closest('.comment-reply-btn:not(.comment-edit-btn)');
            if (replyButton && onReply) {
                event.preventDefault();
                event.stopPropagation();
                onReply(
                    parseInt(replyButton.getAttribute('data-comment-id'), 10) || 0,
                    replyButton.getAttribute('data-comment-username') || ''
                );
                return;
            }

            var editButton = event.target.closest('.comment-edit-btn');
            if (editButton && onEdit) {
                onEdit(parseInt(editButton.getAttribute('data-comment-id'), 10) || 0);
                return;
            }

            var deleteButton = event.target.closest('.comment-delete-btn[data-comment-id]');
            if (deleteButton && onDelete) {
                onDelete(parseInt(deleteButton.getAttribute('data-comment-id'), 10) || 0);
                return;
            }

            var link = event.target.closest('a[href^="#comment-"]');
            if (!link) return;
            event.preventDefault();
            event.stopPropagation();
            var href = link.getAttribute('href');
            var commentId = link.getAttribute('data-target-comment-id') || '';
            if (commentId) {
                href = '#comment-' + commentId;
            }
            setTimeout(function() {
                highlightCommentByHash(href);
            }, 0);
        });

        window.addEventListener('load', function() {
            consumeInitialCommentHash();
        });
    }

    window.GalCommentInteractions = {
        init: init,
        highlightCommentByHash: highlightCommentByHash
    };
})(window);
