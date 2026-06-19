/**
 * 用户头像下拉菜单交互
 */
document.addEventListener('click', function(e) {
    var avatarBtn = e.target.closest('.user-avatar-btn');
    if (avatarBtn) {
        e.preventDefault();
        e.stopPropagation();
        var dropdown = avatarBtn.parentElement.querySelector('.user-dropdown');
        if (dropdown) {
            var isOpen = dropdown.classList.contains('open');
            // 关闭所有下拉
            closeAllDropdowns();
            if (!isOpen) {
                dropdown.classList.add('open');
            }
        }
        return;
    }

    // 点击下拉内部链接，不阻止
    if (e.target.closest('.user-dropdown')) {
        return;
    }

    // 点击外部，关闭下拉
    closeAllDropdowns();
});

function closeAllDropdowns() {
    var dropdowns = document.querySelectorAll('.user-dropdown.open');
    for (var i = 0; i < dropdowns.length; i++) {
        dropdowns[i].classList.remove('open');
    }
}

// ESC 键关闭
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAllDropdowns();
    }
});
