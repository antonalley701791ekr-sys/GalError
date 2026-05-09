(function() {
    'use strict';

    var carousel = document.querySelector('.doc-carousel');
    if (!carousel) return;

    var track = carousel.querySelector('.doc-carousel-track');
    var slides = carousel.querySelectorAll('.doc-carousel-slide');
    var prevBtn = carousel.querySelector('.doc-carousel-prev');
    var nextBtn = carousel.querySelector('.doc-carousel-next');
    var dots = carousel.querySelectorAll('.doc-carousel-dot');
    var totalSlides = slides.length;

    if (totalSlides === 0) return;

    var currentIndex = 0;
    var autoplayTimer = null;
    var autoplayInterval = 5000;
    var isTransitioning = false;

    // Touch state
    var touchStartX = 0;
    var touchStartY = 0;
    var touchDeltaX = 0;
    var isSwiping = false;
    var swipeThreshold = 50;

    function goToSlide(index) {
        if (isTransitioning) return;
        if (index < 0) index = totalSlides - 1;
        if (index >= totalSlides) index = 0;

        isTransitioning = true;
        currentIndex = index;
        track.style.transform = 'translateX(-' + (currentIndex * 100) + '%)';

        // Update dots
        for (var i = 0; i < dots.length; i++) {
            if (i === currentIndex) {
                dots[i].classList.add('active');
            } else {
                dots[i].classList.remove('active');
            }
        }

        setTimeout(function() {
            isTransitioning = false;
        }, 500);
    }

    function nextSlide() {
        goToSlide(currentIndex + 1);
    }

    function prevSlide() {
        goToSlide(currentIndex - 1);
    }

    // Autoplay
    function startAutoplay() {
        stopAutoplay();
        if (totalSlides <= 1) return;
        autoplayTimer = setInterval(nextSlide, autoplayInterval);
    }

    function stopAutoplay() {
        if (autoplayTimer) {
            clearInterval(autoplayTimer);
            autoplayTimer = null;
        }
    }

    // Arrow events
    if (prevBtn) {
        prevBtn.addEventListener('click', function(e) {
            e.preventDefault();
            prevSlide();
            stopAutoplay();
            startAutoplay();
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', function(e) {
            e.preventDefault();
            nextSlide();
            stopAutoplay();
            startAutoplay();
        });
    }

    // Dot events
    for (var i = 0; i < dots.length; i++) {
        (function(idx) {
            dots[idx].addEventListener('click', function(e) {
                e.preventDefault();
                goToSlide(idx);
                stopAutoplay();
                startAutoplay();
            });
        })(i);
    }

    // Hover pause
    carousel.addEventListener('mouseenter', stopAutoplay);
    carousel.addEventListener('mouseleave', startAutoplay);

    // Touch support
    carousel.addEventListener('touchstart', function(e) {
        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
        touchDeltaX = 0;
        isSwiping = false;
        stopAutoplay();
    }, { passive: true });

    carousel.addEventListener('touchmove', function(e) {
        var dx = e.touches[0].clientX - touchStartX;
        var dy = e.touches[0].clientY - touchStartY;

        // Determine if horizontal swipe
        if (!isSwiping && Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 10) {
            isSwiping = true;
        }

        if (isSwiping) {
            e.preventDefault();
            touchDeltaX = dx;
            var offset = -(currentIndex * 100) + (touchDeltaX / carousel.offsetWidth * 100);
            track.style.transition = 'none';
            track.style.transform = 'translateX(' + offset + '%)';
        }
    }, { passive: false });

    carousel.addEventListener('touchend', function() {
        if (isSwiping) {
            isTransitioning = false;
            track.style.transition = '';
            if (touchDeltaX > swipeThreshold) {
                prevSlide();
            } else if (touchDeltaX < -swipeThreshold) {
                nextSlide();
            } else {
                goToSlide(currentIndex);
            }
        }
        isSwiping = false;
        touchDeltaX = 0;
        startAutoplay();
    }, { passive: true });

    // Visibility API - pause when tab is hidden
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopAutoplay();
        } else {
            startAutoplay();
        }
    });

    // Start
    startAutoplay();
})();
