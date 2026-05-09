(function() {
    'use strict';

    var API_URL = window.__captchaApiUrl || '/api/captcha.php';
    var MAX_FETCH_RETRIES = 5;
    var XHR_TIMEOUT = 10000;

    function SliderCaptcha() {
        this.overlay = null;
        this.modal = null;
        this.bgImg = null;
        this.pieceImg = null;
        this.track = null;
        this.thumb = null;
        this.fill = null;
        this.hint = null;
        this.status = null;
        this.body = null;
        this.token = '';
        this.pieceY = 0;
        this.isDragging = false;
        this.startX = 0;
        this.currentX = 0;
        this.maxX = 0;
        this.imgScale = 1;
        this.imgWidth = 320;
        this.pieceWidth = 50;
        this.verified = false;
        this.fetchRetries = 0;
        this.onSuccess = null;
    }

    SliderCaptcha.prototype.show = function() {
        this._createModal();
        document.body.appendChild(this.overlay);
        this._fetchChallenge();
    };

    SliderCaptcha.prototype._createModal = function() {
        // Overlay
        this.overlay = document.createElement('div');
        this.overlay.className = 'captcha-overlay';

        // Modal
        this.modal = document.createElement('div');
        this.modal.className = 'captcha-modal';

        // Header
        var header = document.createElement('div');
        header.className = 'captcha-header';
        header.innerHTML =
            '<svg class="captcha-header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">' +
            '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>' +
            '<span class="captcha-header-title">\u5b89\u5168\u9a8c\u8bc1</span>';
        this.modal.appendChild(header);

        // Body (image area)
        this.body = document.createElement('div');
        this.body.className = 'captcha-body';
        this.body.innerHTML =
            '<div class="captcha-loading">' +
            '<div class="captcha-loading-spinner"></div>' +
            '\u52a0\u8f7d\u4e2d...</div>';
        this.modal.appendChild(this.body);

        // Slider area
        var sliderArea = document.createElement('div');
        sliderArea.className = 'captcha-slider-area';

        this.track = document.createElement('div');
        this.track.className = 'captcha-slider-track';

        this.fill = document.createElement('div');
        this.fill.className = 'captcha-slider-fill';
        this.track.appendChild(this.fill);

        this.thumb = document.createElement('div');
        this.thumb.className = 'captcha-slider-thumb';
        this.thumb.innerHTML =
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">' +
            '<polyline points="9 18 15 12 9 6"/></svg>';
        this.track.appendChild(this.thumb);

        sliderArea.appendChild(this.track);
        this.modal.appendChild(sliderArea);

        // Hint
        this.hint = document.createElement('p');
        this.hint.className = 'captcha-hint';
        this.hint.textContent = '\u62d6\u52a8\u6ed1\u5757\u5b8c\u6210\u62fc\u56fe\u9a8c\u8bc1';
        this.modal.appendChild(this.hint);

        // Status
        this.status = document.createElement('div');
        this.status.className = 'captcha-status';
        this.modal.appendChild(this.status);

        this.overlay.appendChild(this.modal);

        // Prevent overlay click from closing
        this.overlay.addEventListener('click', function(e) {
            e.stopPropagation();
        });

        // Init drag
        this._initSlider();
    };

    SliderCaptcha.prototype._fetchChallenge = function() {
        var self = this;

        // Retry limit check
        if (this.fetchRetries >= MAX_FETCH_RETRIES) {
            this._showStatus('\u52a0\u8f7d\u5931\u8d25\uff0c\u8bf7\u5237\u65b0\u9875\u9762\u91cd\u8bd5', 'error');
            this.hint.textContent = '';
            // Show a refresh button in the hint area
            var refreshBtn = document.createElement('button');
            refreshBtn.textContent = '\u5237\u65b0\u9875\u9762';
            refreshBtn.style.cssText = 'background:var(--accent-purple);color:#fff;border:none;padding:8px 20px;border-radius:20px;cursor:pointer;font-size:14px;';
            refreshBtn.onclick = function() { window.location.reload(); };
            this.hint.appendChild(refreshBtn);
            return;
        }

        this.track.className = 'captcha-slider-track';
        this.status.textContent = '';
        this.status.className = 'captcha-status';

        // Show loading
        this.body.innerHTML =
            '<div class="captcha-loading">' +
            '<div class="captcha-loading-spinner"></div>' +
            '\u52a0\u8f7d\u4e2d...</div>';

        // Reset slider
        this.thumb.style.left = '0px';
        this.fill.style.width = '0px';
        if (this.pieceImg) {
            this.pieceImg.style.left = '0px';
        }

        var xhr = new XMLHttpRequest();
        xhr.open('GET', API_URL, true);
        xhr.timeout = XHR_TIMEOUT;
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        self.fetchRetries = 0;
                        self._renderChallenge(data);
                    } else {
                        self.fetchRetries++;
                        self._showStatus(data.message || '\u83b7\u53d6\u9a8c\u8bc1\u7801\u5931\u8d25', 'error');
                        var delay = (data.retry_after || 3) * 1000;
                        setTimeout(function() { self._fetchChallenge(); }, delay);
                    }
                } catch (e) {
                    self.fetchRetries++;
                    self._showStatus('\u89e3\u6790\u54cd\u5e94\u5931\u8d25', 'error');
                }
            } else if (xhr.status === 429) {
                self.fetchRetries++;
                try {
                    var errData = JSON.parse(xhr.responseText);
                    self._showStatus(errData.message || '\u8bf7\u6c42\u8fc7\u4e8e\u9891\u7e41', 'error');
                } catch (e) {
                    self._showStatus('\u8bf7\u6c42\u8fc7\u4e8e\u9891\u7e41\uff0c\u8bf7\u7a0d\u540e\u518d\u8bd5', 'error');
                }
                setTimeout(function() { self._fetchChallenge(); }, 5000);
            } else {
                self.fetchRetries++;
                self._showStatus('\u7f51\u7edc\u9519\u8bef\uff0c\u8bf7\u7a0d\u540e\u91cd\u8bd5', 'error');
                setTimeout(function() { self._fetchChallenge(); }, 3000);
            }
        };
        xhr.ontimeout = function() {
            self.fetchRetries++;
            self._showStatus('\u8bf7\u6c42\u8d85\u65f6\uff0c\u8bf7\u7a0d\u540e\u91cd\u8bd5', 'error');
            setTimeout(function() { self._fetchChallenge(); }, 3000);
        };
        xhr.send();
    };

    SliderCaptcha.prototype._renderChallenge = function(data) {
        var self = this;
        this.token = data.token;
        this.pieceY = data.y;
        this.imgWidth = data.imgWidth || 320;
        this.pieceWidth = data.pieceWidth || 50;

        // Clear loading
        this.body.innerHTML = '';

        // Background image
        this.bgImg = document.createElement('img');
        this.bgImg.className = 'captcha-bg';
        this.bgImg.src = data.bg;
        this.bgImg.draggable = false;
        this.body.appendChild(this.bgImg);

        // Puzzle piece
        this.pieceImg = document.createElement('img');
        this.pieceImg.className = 'captcha-piece';
        this.pieceImg.src = data.piece;
        this.pieceImg.draggable = false;
        this.pieceImg.style.left = '0px';
        this.body.appendChild(this.pieceImg);

        // Wait for image to load to get dimensions and calculate scale
        this.bgImg.onload = function() {
            var displayedWidth = self.bgImg.clientWidth;
            self.imgScale = displayedWidth / self.imgWidth;
            self.pieceImg.style.top = Math.round(self.pieceY * self.imgScale) + 'px';

            // Scale the piece image
            var pieceNaturalW = self.pieceImg.naturalWidth || self.pieceWidth;
            var pieceNaturalH = self.pieceImg.naturalHeight || self.pieceWidth;
            self.pieceImg.style.width = Math.round(pieceNaturalW * self.imgScale) + 'px';
            self.pieceImg.style.height = Math.round(pieceNaturalH * self.imgScale) + 'px';

            // Calculate max slider movement
            var trackWidth = self.track.clientWidth;
            var thumbWidth = self.thumb.clientWidth;
            self.maxX = trackWidth - thumbWidth;
        };
    };

    SliderCaptcha.prototype._initSlider = function() {
        var self = this;

        var onStart = function(e) {
            if (self.verified) return;
            e.preventDefault();
            self.isDragging = true;
            self.startX = (e.touches ? e.touches[0].clientX : e.clientX);
            self.currentX = 0;
            self.track.className = 'captcha-slider-track';
            self.status.textContent = '';
            self.status.className = 'captcha-status';
            if (self.pieceImg) {
                self.pieceImg.classList.remove('fail', 'success');
            }
        };

        var onMove = function(e) {
            if (!self.isDragging) return;
            e.preventDefault();
            var clientX = e.touches ? e.touches[0].clientX : e.clientX;
            var deltaX = clientX - self.startX;
            deltaX = Math.max(0, Math.min(deltaX, self.maxX));
            self.currentX = deltaX;

            self.thumb.style.left = deltaX + 'px';
            self.fill.style.width = (deltaX + self.thumb.clientWidth / 2) + 'px';

            // Move piece proportionally
            if (self.pieceImg && self.bgImg) {
                var displayedWidth = self.bgImg.clientWidth;
                var thumbWidth = self.thumb.clientWidth;
                var trackWidth = self.track.clientWidth;
                var ratio = deltaX / (trackWidth - thumbWidth);
                var maxPieceX = displayedWidth - (self.pieceImg.clientWidth || 50);
                self.pieceImg.style.left = Math.round(ratio * maxPieceX) + 'px';
            }
        };

        var onEnd = function(e) {
            if (!self.isDragging) return;
            self.isDragging = false;

            if (self.currentX < 5) return; // Too small movement, ignore

            // Calculate the actual X position in original image coordinates using server-provided values
            var trackWidth = self.track.clientWidth;
            var thumbWidth = self.thumb.clientWidth;
            var ratio = self.currentX / (trackWidth - thumbWidth);
            var maxOrigX = self.imgWidth - self.pieceWidth;
            var answerX = Math.round(ratio * maxOrigX);

            self._verify(answerX);
        };

        this.thumb.addEventListener('mousedown', onStart);
        this.thumb.addEventListener('touchstart', onStart, { passive: false });
        document.addEventListener('mousemove', onMove);
        document.addEventListener('touchmove', onMove, { passive: false });
        document.addEventListener('mouseup', onEnd);
        document.addEventListener('touchend', onEnd);

        // Store for cleanup
        this._cleanupFns = function() {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('mouseup', onEnd);
            document.removeEventListener('touchend', onEnd);
        };
    };

    SliderCaptcha.prototype._verify = function(x) {
        var self = this;

        var xhr = new XMLHttpRequest();
        xhr.open('POST', API_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.timeout = XHR_TIMEOUT;
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.success) {
                    self._onSuccess();
                } else {
                    self._onFail(data.message || '\u9a8c\u8bc1\u5931\u8d25');
                }
            } catch (e) {
                self._onFail('\u7f51\u7edc\u9519\u8bef');
            }
        };
        xhr.ontimeout = function() {
            self._onFail('\u8bf7\u6c42\u8d85\u65f6');
        };
        xhr.send(JSON.stringify({ token: this.token, x: x }));
    };

    SliderCaptcha.prototype._onSuccess = function() {
        this.verified = true;
        this.track.className = 'captcha-slider-track success';
        if (this.pieceImg) {
            this.pieceImg.classList.add('success');
        }
        this._showStatus('\u9a8c\u8bc1\u901a\u8fc7', 'success');

        // Change thumb icon to checkmark
        this.thumb.innerHTML =
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">' +
            '<polyline points="20 6 9 17 4 12"/></svg>';

        var self = this;
        setTimeout(function() {
            self.overlay.classList.add('closing');
            setTimeout(function() {
                self.destroy();
                if (typeof self.onSuccess === 'function') {
                    self.onSuccess();
                } else {
                    window.location.reload();
                }
            }, 400);
        }, 800);
    };

    SliderCaptcha.prototype._onFail = function(message) {
        this.track.className = 'captcha-slider-track fail';
        if (this.pieceImg) {
            this.pieceImg.classList.add('fail');
        }
        this._showStatus(message, 'error');

        var self = this;
        setTimeout(function() {
            self._fetchChallenge();
        }, 800);
    };

    SliderCaptcha.prototype._showStatus = function(text, type) {
        this.status.textContent = text;
        this.status.className = 'captcha-status' + (type ? ' ' + type : '');
    };

    SliderCaptcha.prototype.destroy = function() {
        if (this._cleanupFns) {
            this._cleanupFns();
        }
        if (this.overlay && this.overlay.parentNode) {
            this.overlay.parentNode.removeChild(this.overlay);
        }
    };

    // Auto-init: check if captcha is required
    document.addEventListener('DOMContentLoaded', function() {
        if (window.__captchaRequired) {
            var captcha = new SliderCaptcha();
            captcha.show();
        }
    });

    window.SliderCaptcha = SliderCaptcha;
})();
