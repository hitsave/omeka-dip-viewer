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
        var downloadLink = root.querySelector('.omeka-dip-video-download-link');
        var downloadSize = root.querySelector('.omeka-dip-video-download-size');

        var player = window.videojs(playerEl, {
            fluid: true,
            responsive: false,
        });

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

            picks.forEach(function (p) {
                var selected = p === pick;
                p.classList.toggle('is-selected', selected);
                p.setAttribute('aria-current', selected ? 'true' : 'false');
            });
        }

        var embla = initEmbla(root);

        if (embla) {
            embla.on('select', function () {
                applyPick(picks[embla.selectedScrollSnap()]);
            });
        }

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
            applyPick(picks[0]);
        });
    }

    function boot() {
        document.querySelectorAll('[data-omeka-dip-videos]').forEach(initVideoSection);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
