(function () {
    'use strict';

    function formatBytes(bytes) {
        var n = parseInt(bytes, 10);
        if (!n || n < 0) {
            return '';
        }
        return '(' + n.toLocaleString() + ' B)';
    }

    function initEmbla(root) {
        var viewport = root.querySelector('.omeka-dip-video-embla .omeka-dip-embla__viewport');
        if (!viewport || typeof EmblaCarousel !== 'function') {
            return null;
        }

        var emblaOptions = {
            align: 'start',
            containScroll: 'trimSnaps',
            dragFree: true,
            slidesToScroll: 'auto',
            watchSlides: false,
        };
        var embla = window.OmekaDipEmbla
            ? window.OmekaDipEmbla.create(viewport, emblaOptions)
            : EmblaCarousel(viewport, emblaOptions);

        var prevBtn = root.querySelector('.omeka-dip-video-embla .omeka-dip-embla__prev');
        var nextBtn = root.querySelector('.omeka-dip-video-embla .omeka-dip-embla__next');

        function syncButtons() {
            if (prevBtn) {
                prevBtn.disabled = !embla.canScrollPrev();
            }
            if (nextBtn) {
                nextBtn.disabled = !embla.canScrollNext();
            }
        }

        embla.on('select', syncButtons);
        embla.on('reInit', syncButtons);
        embla.on('pointerDown', function () {
            viewport.classList.add('is-dragging');
        });
        embla.on('pointerUp', function () {
            viewport.classList.remove('is-dragging');
        });
        syncButtons();

        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                embla.scrollPrev();
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                embla.scrollNext();
            });
        }

        return embla;
    }

    function initVideoSection(root) {
        if (typeof window.videojs !== 'function') {
            return;
        }

        var playerEl = root.querySelector('video-js');
        if (!playerEl || playerEl.dataset.omekaDipVideoInit) {
            return;
        }
        playerEl.dataset.omekaDipVideoInit = '1';

        var picks = root.querySelectorAll('.omeka-dip-video-pick');
        if (!picks.length) {
            return;
        }

        var caption = root.querySelector('.omeka-dip-video-caption');
        var sheetWrap = root.querySelector('.omeka-dip-video-sheet');
        var sheetLink = root.querySelector('.omeka-dip-video-sheet-link');
        var sheetDialog = root.querySelector('.omeka-dip-video-sheet-dialog');

        // The contact sheet opens in a lightbox like gallery images (a new tab only on
        // ctrl/cmd/middle click, or without <dialog> support).
        if (sheetLink && sheetDialog && sheetDialog.showModal) {
            var sheetImg = sheetDialog.querySelector('.omeka-dip-gallery-full');
            var sheetCaption = sheetDialog.querySelector('.omeka-dip-gallery-caption');
            sheetLink.addEventListener('click', function (event) {
                var href = sheetLink.getAttribute('href') || '';
                if (!href || href === '#' || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
                    return;
                }
                event.preventDefault();
                var label = sheetLink.getAttribute('data-label') || '';
                sheetImg.src = href;
                sheetImg.alt = label ? 'Contact sheet: ' + label : 'Contact sheet';
                if (sheetCaption) {
                    sheetCaption.textContent = sheetImg.alt;
                }
                sheetDialog.showModal();
            });
            sheetDialog.addEventListener('click', function (event) {
                if (event.target === sheetDialog) {
                    sheetDialog.close();
                }
            });
            sheetDialog.addEventListener('close', function () {
                sheetImg.removeAttribute('src');
                sheetImg.alt = '';
            });
        }

        function updateSheet(pick) {
            var sheet = pick ? pick.getAttribute('data-sheet-src') || '' : '';
            if (sheetWrap && sheetLink) {
                sheetWrap.hidden = sheet === '';
                sheetLink.href = sheet || '#';
                sheetLink.setAttribute('data-label', pick ? pick.getAttribute('data-label') || '' : '');
            }
        }
        var downloadLink = root.querySelector('.omeka-dip-video-download-link');
        var downloadSize = root.querySelector('.omeka-dip-video-download-size');

        var player = window.videojs(playerEl, {
            fluid: true,
            responsive: false,
        });

        function selectedPick() {
            for (var i = 0; i < picks.length; i++) {
                if (picks[i].classList.contains('is-selected')) {
                    return picks[i];
                }
            }
            return picks[0] || null;
        }

        function primeDownloadUi(pick) {
            if (!pick) {
                return;
            }
            var label = pick.getAttribute('data-label') || '';
            var downloadSrc = pick.getAttribute('data-download-src') || '#';
            var sizeBytes = pick.getAttribute('data-size-bytes');
            if (caption && !caption.textContent) {
                caption.textContent = label;
            }
            if (downloadLink && downloadLink.getAttribute('href') === '#') {
                downloadLink.href = downloadSrc;
                downloadLink.textContent = label;
            }
            if (downloadSize && !downloadSize.textContent) {
                downloadSize.textContent = formatBytes(sizeBytes);
            }
            updateSheet(pick);
        }

        function applyPick(pick) {
            if (!pick) {
                return;
            }
            var src = pick.getAttribute('data-playback-src');
            var type = pick.getAttribute('data-playback-type') || 'video/mp4';
            var label = pick.getAttribute('data-label') || '';
            var downloadSrc = pick.getAttribute('data-download-src') || '#';
            var sizeBytes = pick.getAttribute('data-size-bytes');

            if (!src) {
                return;
            }

            player.pause();
            player.src({ src: src, type: type });

            if (caption) {
                caption.textContent = label;
            }
            if (downloadLink) {
                downloadLink.href = downloadSrc;
                downloadLink.textContent = label;
            }
            if (downloadSize) {
                downloadSize.textContent = formatBytes(sizeBytes);
            }
            updateSheet(pick);

            picks.forEach(function (p) {
                var selected = p === pick;
                p.classList.toggle('is-selected', selected);
                p.setAttribute('aria-current', selected ? 'true' : 'false');
            });
        }

        var embla = initEmbla(root);

        if (embla) {
            embla.on('select', function () {
                if (root.dataset.dipVideoStarted !== '1') {
                    return;
                }
                applyPick(picks[embla.selectedScrollSnap()]);
            });
        }

        primeDownloadUi(selectedPick());

        picks.forEach(function (pick, index) {
            var pointerDragged = false;
            pick.addEventListener('pointerdown', function () {
                pointerDragged = false;
            });
            pick.addEventListener('pointermove', function () {
                pointerDragged = true;
            });
            pick.addEventListener('click', function () {
                if (pointerDragged) {
                    return;
                }
                root.dataset.dipVideoStarted = '1';
                applyPick(pick);
                if (embla) {
                    embla.scrollTo(index);
                }
            });
            pick.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }
                event.preventDefault();
                pick.click();
            });
        });

        player.ready(function () {
            player.one('play', function () {
                root.dataset.dipVideoStarted = '1';
                applyPick(selectedPick());
            });
        });
    }

    function boot() {
        document.querySelectorAll('[data-omeka-dip-videos]').forEach(initVideoSection);
    }

    // The package tree plays a video here instead of downloading it.
    window.OmekaDipVideos = {
        play: function (key) {
            var pick = document.querySelector('.omeka-dip-video-pick[data-dip-video-key="' + CSS.escape(key) + '"]');
            if (!pick) {
                return false;
            }
            pick.click();
            var section = pick.closest('[data-omeka-dip-videos]');
            if (section) {
                section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                var playerEl = section.querySelector('video-js');
                if (playerEl && typeof window.videojs === 'function') {
                    window.videojs(playerEl).play();
                }
            }
            return true;
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
