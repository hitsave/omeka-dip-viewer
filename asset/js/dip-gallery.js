(function () {
    'use strict';

    var TAP_THRESHOLD_PX = 10;

    function getGalleryIndex(root) {
        if (root._dipGalleryIndex) {
            return root._dipGalleryIndex;
        }
        var el = root.querySelector('script.omeka-dip-gallery-index');
        if (el && el.textContent) {
            try {
                var parsed = JSON.parse(el.textContent);
                if (Array.isArray(parsed)) {
                    root._dipGalleryIndex = parsed;
                    return parsed;
                }
            } catch (e) {
                /* fall through */
            }
        }
        var slides = root.querySelectorAll('.omeka-dip-gallery-open');
        var fallback = [];
        slides.forEach(function (slide) {
            var src = slide.getAttribute('data-dip-src');
            if (!src) {
                return;
            }
            fallback.push({
                key: slide.getAttribute('data-dip-key') || '',
                src: src,
                label: slide.getAttribute('data-dip-label') || '',
            });
        });
        root._dipGalleryIndex = fallback;
        return fallback;
    }

    function indexOfSlideInGallery(slide, root) {
        var items = getGalleryIndex(root);
        var key = slide.getAttribute('data-dip-key');
        if (key) {
            for (var i = 0; i < items.length; i++) {
                if (items[i].key === key) {
                    return i;
                }
            }
        }
        var src = slide.getAttribute('data-dip-src');
        if (src) {
            for (var j = 0; j < items.length; j++) {
                if (items[j].src === src) {
                    return j;
                }
            }
        }
        return -1;
    }

    function showLightboxAtIndex(root, index, dialog, fullImg, caption) {
        var items = getGalleryIndex(root);
        if (!items.length || index < 0 || index >= items.length) {
            return;
        }
        var entry = items[index];
        dialog.dataset.dipLightboxIndex = String(index);
        fullImg.src = entry.src;
        fullImg.alt = entry.label || '';
        if (caption) {
            if (items.length > 1) {
                caption.textContent = (entry.label || '') + ' (' + (index + 1) + ' / ' + items.length + ')';
            } else {
                caption.textContent = entry.label || '';
            }
        }
        var opening = !dialog.open;
        dialog.showModal();
        if (opening) {
            bindLightboxKeyboard(root, dialog, fullImg, caption);
        }
    }

    function openLightboxFromSlide(slide, root, dialog, fullImg, caption) {
        var idx = indexOfSlideInGallery(slide, root);
        if (idx < 0) {
            return;
        }
        showLightboxAtIndex(root, idx, dialog, fullImg, caption);
    }

    function bindLightboxKeyboard(root, dialog, fullImg, caption) {
        if (dialog._dipLightboxKeyHandler) {
            return;
        }
        dialog._dipLightboxKeyHandler = function (event) {
            if (!dialog.open) {
                return;
            }
            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                navigateLightbox(root, -1, dialog, fullImg, caption);
            } else if (event.key === 'ArrowRight') {
                event.preventDefault();
                navigateLightbox(root, 1, dialog, fullImg, caption);
            }
        };
        document.addEventListener('keydown', dialog._dipLightboxKeyHandler, true);
    }

    function unbindLightboxKeyboard(dialog) {
        if (!dialog._dipLightboxKeyHandler) {
            return;
        }
        document.removeEventListener('keydown', dialog._dipLightboxKeyHandler, true);
        dialog._dipLightboxKeyHandler = null;
    }

    function navigateLightbox(root, delta, dialog, fullImg, caption) {
        var items = getGalleryIndex(root);
        if (items.length < 2) {
            return;
        }
        var idx = parseInt(dialog.dataset.dipLightboxIndex || '0', 10);
        if (isNaN(idx)) {
            idx = 0;
        }
        var next = idx + delta;
        if (next < 0) {
            next = items.length - 1;
        } else if (next >= items.length) {
            next = 0;
        }
        showLightboxAtIndex(root, next, dialog, fullImg, caption);
    }

    function bindViewportTap(root, dialog, fullImg, caption) {
        var viewport = root.querySelector('.omeka-dip-embla__viewport');
        if (!viewport || viewport.dataset.dipLightboxTap === '1') {
            return;
        }
        viewport.dataset.dipLightboxTap = '1';

        var tapTarget = null;
        var downX = 0;
        var downY = 0;

        viewport.addEventListener(
            'pointerdown',
            function (event) {
                tapTarget = event.target.closest('.omeka-dip-gallery-open');
                if (tapTarget) {
                    downX = event.clientX;
                    downY = event.clientY;
                }
            },
            true
        );

        viewport.addEventListener(
            'pointerup',
            function (event) {
                if (!tapTarget) {
                    return;
                }
                var open = event.target.closest('.omeka-dip-gallery-open');
                if (!open || open !== tapTarget) {
                    tapTarget = null;
                    return;
                }
                var dx = Math.abs(event.clientX - downX);
                var dy = Math.abs(event.clientY - downY);
                tapTarget = null;
                if (dx > TAP_THRESHOLD_PX || dy > TAP_THRESHOLD_PX) {
                    return;
                }
                openLightboxFromSlide(open, root, dialog, fullImg, caption);
            },
            true
        );
    }

    function initLightbox(root) {
        var dialog = root.querySelector('.omeka-dip-gallery-dialog');
        var fullImg = root.querySelector('.omeka-dip-gallery-full');
        var caption = root.querySelector('.omeka-dip-gallery-caption');
        if (!dialog || !fullImg || !dialog.showModal) {
            return;
        }

        bindViewportTap(root, dialog, fullImg, caption);

        root.querySelectorAll('.omeka-dip-gallery-open:not([data-dip-lightbox-bound])').forEach(function (slide) {
            slide.setAttribute('data-dip-lightbox-bound', '1');
            slide.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }
                event.preventDefault();
                openLightboxFromSlide(slide, root, dialog, fullImg, caption);
            });
        });

        if (!dialog.dataset.dipLightboxDialogBound) {
            dialog.dataset.dipLightboxDialogBound = '1';
            dialog.addEventListener('click', function (event) {
                if (event.target === dialog) {
                    dialog.close();
                }
            });
            dialog.addEventListener('close', function () {
                unbindLightboxKeyboard(dialog);
                fullImg.removeAttribute('src');
                fullImg.alt = '';
                if (caption) {
                    caption.textContent = '';
                }
                delete dialog.dataset.dipLightboxIndex;
            });
        }
    }

    function initEmbla(root) {
        var viewport = root.querySelector('.omeka-dip-embla__viewport');
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

        var prevBtn = root.querySelector('.omeka-dip-embla__prev');
        var nextBtn = root.querySelector('.omeka-dip-embla__next');

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

    function initGallery(root) {
        initLightbox(root);
        var embla = initEmbla(root);

        var showAll = root.querySelector('.omeka-dip-gallery-show-all');
        if (showAll) {
            showAll.addEventListener('click', function () {
                var container = root.querySelector('.omeka-dip-embla__container');
                var template = root.querySelector('template.omeka-dip-gallery-more-slides');
                if (container && template && template.content) {
                    container.appendChild(document.importNode(template.content, true));
                    template.remove();
                }
                var wrap = root.querySelector('.omeka-dip-gallery-more-wrap');
                if (wrap) {
                    wrap.remove();
                }
                initLightbox(root);
                if (embla) {
                    embla.reInit();
                }
            });
        }
    }

    function boot() {
        document.querySelectorAll('.omeka-dip-gallery').forEach(initGallery);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
