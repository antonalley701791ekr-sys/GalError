(function() {
    'use strict';
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof MarkdownEditor === 'undefined') return;
        MarkdownEditor.init({ textareaId: 'md-editor', previewId: 'md-preview', imageUploadUrl: 'pages.php' });
        var form = document.querySelector('form[method="post"]');
        if (!form) return;
        var textarea = document.getElementById('md-editor');
        var preview = document.getElementById('md-preview');
        if (textarea && preview && typeof MarkdownEditor !== 'undefined') {
            MarkdownEditor.init({ textareaId: 'md-editor', previewId: 'md-preview', imageUploadUrl: 'pages.php' });
        }
    });
})();
