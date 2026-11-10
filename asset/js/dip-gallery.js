(function () {
    'use strict';

    var TAP_THRESHOLD_PX = 10;
    var SWIPE_THRESHOLD_PX = 48;

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

    function captionForEntry(entry, index, total) {
        var label = entry.label || '';
        if (total > 1) {
            return label + ' (' + (index + 1) + ' / ' + total + ')';
        }
        return label;
    }

    function loadingAltText(label, percent) {
        var status =
            percent === null || percent === undefined
                ? 'Loading...'
                : 'Loading... ' + percent + '%';
        return label ? label + ' — ' + status : status;
    }

    function setLightboxLoading(fullImg, caption, label, percent) {
        fullImg.alt = loadingAltText(label, percent);
        if (caption) {
            caption.textContent =
                percent === null || percent === undefined
                    ? 'Loading...'
                    : 'Loading... ' + percent + '%';
            caption.classList.add('is-loading');
        }
    }

    function setLightboxReady(fullImg, caption, entry, index, total) {
        fullImg.alt = entry.label || '';
        if (caption) {
            caption.textContent = captionForEntry(entry, index, total);
            caption.classList.remove('is-loading');
        }
    }

    function showLightboxAtIndex(root, index, dialog, fullImg, caption) {
        var items = getGalleryIndex(root);
        if (!items.length || index < 0 || index >= items.length) {
            return;
        }
        var entry = items[index];
        dialog.dataset.dipLightboxIndex = String(index);
        var thumbSrc = entry.src;
        var fullSrc = entry.fullSrc || entry.src;
        var label = entry.label || '';
        var needsFullLoad = thumbSrc && fullSrc && fullSrc !== thumbSrc;

        if (window.OmekaDipMediaQueue) {
            window.OmekaDipMediaQueue.cancelProgressLoad(fullImg);
        }
        fullImg.removeAttribute('data-dip-loaded');
        fullImg.removeAttribute('data-dip-queued');
        if (needsFullLoad) {
            fullImg.src = thumbSrc;
            setLightboxLoading(fullImg, caption, label, 0);
        } else {
            fullImg.removeAttribute('src');
            setLightboxReady(fullImg, caption, entry, index, items.length);
        }

        if (window.OmekaDipMediaQueue) {
            if (needsFullLoad && window.OmekaDipMediaQueue.loadImageWithProgress) {
                window.OmekaDipMediaQueue.loadImageWithProgress(fullImg, fullSrc, {
                    onProgress: function (percent) {
                        if (parseInt(dialog.dataset.dipLightboxIndex || '-1', 10) !== index) {
                            return;
                        }
                        setLightboxLoading(fullImg, caption, label, percent);
                    },
                    onComplete: function () {
                        if (parseInt(dialog.dataset.dipLightboxIndex || '-1', 10) !== index) {
                            return;
                        }
                        setLightboxReady(fullImg, caption, entry, index, items.length);
                    },
                });
            } else {
                window.OmekaDipMediaQueue.loadImage(fullImg, fullSrc, { priority: true });
                setLightboxReady(fullImg, caption, entry, index, items.length);
            }
            prefetchLightboxNeighbors(root, index);
        } else {
            fullImg.src = fullSrc;
            setLightboxReady(fullImg, caption, entry, index, items.length);
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

    function bindLightboxSwipe(root, dialog, fullImg, caption) {
        if (dialog._dipLightboxSwipeBound) {
            return;
        }
        dialog._dipLightboxSwipeBound = true;
        var body = dialog.querySelector('.omeka-dip-gallery-dialog-body') || dialog;
        var startX = 0;
        var startY = 0;
        var tracking = false;

        body.addEventListener(
            'pointerdown',
            function (event) {
                if (!dialog.open || event.button !== 0) {
                    return;
                }
                if (event.target.closest('.omeka-dip-gallery-close')) {
                    return;
                }
                tracking = true;
                startX = event.clientX;
                startY = event.clientY;
            },
            { passive: true }
        );

        function finishSwipe(event) {
            if (!tracking || !dialog.open) {
                tracking = false;
                return;
            }
            tracking = false;
            var dx = event.clientX - startX;
            var dy = event.clientY - startY;
            if (Math.abs(dx) < SWIPE_THRESHOLD_PX || Math.abs(dx) < Math.abs(dy)) {
                return;
            }
            event.preventDefault();
            navigateLightbox(root, dx > 0 ? -1 : 1, dialog, fullImg, caption);
        }

        body.addEventListener('pointerup', finishSwipe);
        body.addEventListener('pointercancel', function () {
            tracking = false;
        });
    }

    function bindLightboxNavButtons(root, dialog, fullImg, caption) {
        if (dialog._dipLightboxNavBound) {
            return;
        }
        dialog._dipLightboxNavBound = true;
        dialog.querySelectorAll('[data-dip-lightbox-nav]').forEach(function (btn) {
            btn.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                var delta = parseInt(btn.getAttribute('data-dip-lightbox-nav') || '0', 10);
                if (delta) {
                    navigateLightbox(root, delta, dialog, fullImg, caption);
                }
            });
        });
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

    function prefetchLightboxNeighbors(root, index) {
        if (!window.OmekaDipMediaQueue || !window.OmekaDipMediaQueue.prefetchUrl) {
            return;
        }
        var items = getGalleryIndex(root);
        [-1, 1, 2].forEach(function (delta) {
            var i = index + delta;
            if (i < 0 || i >= items.length) {
                return;
            }
            var url = items[i].fullSrc || items[i].src;
            if (url) {
                window.OmekaDipMediaQueue.prefetchUrl(url);
            }
        });
    }

    function prefetchInitialFullImages(root) {
        if (!window.OmekaDipMediaQueue || !window.OmekaDipMediaQueue.prefetchUrl) {
            return;
        }
        var items = getGalleryIndex(root);
        var limit = Math.min(items.length, 6);
        for (var i = 0; i < limit; i++) {
            var url = items[i].fullSrc || items[i].src;
            if (url) {
                window.OmekaDipMediaQueue.prefetchUrl(url);
            }
        }
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
        bindLightboxSwipe(root, dialog, fullImg, caption);
        bindLightboxNavButtons(root, dialog, fullImg, caption);

        root.querySelectorAll('.omeka-dip-gallery-open:not([data-dip-lightbox-bound])').forEach(function (slide) {
            slide.setAttribute('data-dip-lightbox-bound', '1');
            slide.addEventListener('click', function (event) {
                event.preventDefault();
                openLightboxFromSlide(slide, root, dialog, fullImg, caption);
            });
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
                if (window.OmekaDipMediaQueue) {
                    window.OmekaDipMediaQueue.cancelProgressLoad(fullImg);
                    window.OmekaDipMediaQueue.revokeBlobUrl(fullImg);
                }
                fullImg.removeAttribute('src');
                fullImg.removeAttribute('data-dip-src');
                fullImg.removeAttribute('data-dip-loaded');
                fullImg.removeAttribute('data-dip-queued');
                fullImg.alt = '';
                if (caption) {
                    caption.textContent = '';
                    caption.classList.remove('is-loading');
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
                if (window.OmekaDipMediaQueue) {
                    window.OmekaDipMediaQueue.scan(root);
                }
                if (embla) {
                    embla.reInit();
                }
            });
        }

        if (window.OmekaDipMediaQueue) {
            window.OmekaDipMediaQueue.scan(root);
            prefetchInitialFullImages(root);
        }
    }

    /**
     * A dump's own package before its disc contents, whatever the media order. Omeka lists media
     * by position, and media added after the item's last save have none, so they come first.
     */
    function listDiscContentsLast() {
        document.querySelectorAll('.omeka-dip-package[data-dip-kind="disc-contents"]').forEach(function (pkg) {
            var render = pkg.closest('.media-render') || pkg;
            var parent = render.parentNode;
            var last = null;
            parent.querySelectorAll(':scope > .media-render').forEach(function (other) {
                if (!other.querySelector('.omeka-dip-package[data-dip-kind="disc-contents"]')) {
                    last = other;
                }
            });
            if (last && (render.compareDocumentPosition(last) & Node.DOCUMENT_POSITION_FOLLOWING)) {
                parent.insertBefore(render, last.nextSibling);
            }
        });
    }

    function isDisplayableImageUrl(url) {
        return /\.(jpe?g|png|gif|webp|avif|bmp)(\?|#|$)/i.test(url || '');
    }

    function collectNativeMediaEmbedEntries(embeds) {
        var entries = [];
        embeds.querySelectorAll('.media-render.file').forEach(function (render, idx) {
            var link = render.querySelector('a[href]');
            var img = render.querySelector('img[src]');
            if (!link || !img) {
                return;
            }
            var thumbSrc = img.getAttribute('src') || '';
            if (!thumbSrc) {
                return;
            }
            var downloadHref = link.getAttribute('href') || '';
            var nameEl = render.querySelector('.resource-name');
            var label =
                link.getAttribute('title') ||
                (nameEl && nameEl.textContent ? nameEl.textContent.trim() : '') ||
                'Media ' + (idx + 1);
            var fullSrc = isDisplayableImageUrl(downloadHref) ? downloadHref : thumbSrc;
            entries.push({
                key: String(idx),
                src: thumbSrc,
                fullSrc: fullSrc,
                label: label,
                downloadHref: downloadHref,
            });
        });
        return entries;
    }

    function appendGalleryDialog(section) {
        var dialog = document.createElement('dialog');
        dialog.className = 'omeka-dip-gallery-dialog';
        dialog.setAttribute('aria-label', 'Enlarged image');
        dialog.innerHTML =
            '<form method="dialog"><button type="submit" class="omeka-dip-gallery-close" aria-label="Close">×</button></form>' +
            '<button type="button" class="omeka-dip-gallery-lightbox-prev" data-dip-lightbox-nav="-1" aria-label="Previous image">‹</button>' +
            '<button type="button" class="omeka-dip-gallery-lightbox-next" data-dip-lightbox-nav="1" aria-label="Next image">›</button>' +
            '<figure class="omeka-dip-gallery-dialog-body">' +
            '<img src="" alt="" class="omeka-dip-gallery-full" draggable="false">' +
            '<figcaption class="omeka-dip-gallery-caption"></figcaption>' +
            '</figure>';
        section.appendChild(dialog);
    }

    function makeGallerySlide(entry) {
        var slide = document.createElement('div');
        slide.className = 'omeka-dip-embla__slide omeka-dip-gallery-item';
        var open = document.createElement('div');
        open.className = 'omeka-dip-gallery-open';
        open.setAttribute('role', 'button');
        open.setAttribute('tabindex', '0');
        open.setAttribute('data-dip-key', entry.key);
        open.setAttribute('data-dip-src', entry.src);
        open.setAttribute('data-dip-full-src', entry.fullSrc);
        open.setAttribute('data-dip-label', entry.label);
        var img = document.createElement('img');
        img.className = 'omeka-dip-gallery-thumb';
        img.src = entry.src;
        img.alt = entry.label;
        img.decoding = 'async';
        img.draggable = false;
        open.appendChild(img);
        slide.appendChild(open);
        return slide;
    }

    /**
     * Item with a DIP package gallery AND ordinary file media (e.g. disc photos on a record that
     * also holds an archived dump): append the file images to the package gallery as extra slides,
     * so the page has one carousel and one lightbox instead of two.
     */
    function mergeNativeMediaIntoDipGallery() {
        var embeds = document.querySelector('#content .media-embeds');
        if (!embeds || embeds.dataset.omekaDipNativeGallery === '1') {
            return;
        }
        var dip = document.querySelector('.omeka-dip-gallery.omeka-dip-packaged-previews');
        if (!dip) {
            return;
        }
        var container = dip.querySelector('.omeka-dip-embla__container');
        var indexEl = dip.querySelector('script.omeka-dip-gallery-index');
        if (!container || !indexEl) {
            return;
        }
        var duplicates = [];
        try {
            duplicates = JSON.parse(dip.getAttribute('data-dip-duplicate-originals') || '[]');
        } catch (e) {
            duplicates = [];
        }
        // Byte-identical to a file already in the package gallery (server-side SHA-256 match): skip.
        var entries = collectNativeMediaEmbedEntries(embeds).filter(function (entry) {
            return duplicates.indexOf(entry.downloadHref) === -1;
        });
        var index;
        try {
            index = JSON.parse(indexEl.textContent || '[]');
        } catch (e) {
            return;
        }
        entries.forEach(function (entry, i) {
            entry.key = 'media-' + i;
            index.push(entry);
            container.appendChild(makeGallerySlide(entry));
        });
        indexEl.textContent = JSON.stringify(index);
        if (index.length > 1) {
            dip.classList.remove('omeka-dip-gallery--count-1');
        }
        embeds.querySelectorAll('.media-render.file').forEach(function (render) {
            render.remove();
        });
        embeds.dataset.omekaDipNativeGallery = '1';
    }

    function buildNativeMediaGalleryFromEmbeds() {
        var embeds = document.querySelector('#content .media-embeds');
        if (!embeds || embeds.dataset.omekaDipNativeGallery === '1') {
            return;
        }
        if (embeds.querySelector('.omeka-dip-gallery')) {
            return;
        }
        var entries = collectNativeMediaEmbedEntries(embeds);
        if (!entries.length) {
            return;
        }

        embeds.dataset.omekaDipNativeGallery = '1';
        embeds.textContent = '';

        var section = document.createElement('section');
        section.className =
            'omeka-dip-gallery omeka-dip-native-media' +
            (entries.length === 1 ? ' omeka-dip-gallery--count-1' : '');
        section.setAttribute('aria-label', 'Media preview');

        var indexEl = document.createElement('script');
        indexEl.type = 'application/json';
        indexEl.className = 'omeka-dip-gallery-index';
        indexEl.textContent = JSON.stringify(entries);
        section.appendChild(indexEl);

        var embla = document.createElement('div');
        embla.className = 'omeka-dip-embla';
        embla.innerHTML =
            '<div class="omeka-dip-embla__viewport"><div class="omeka-dip-embla__container"></div></div>' +
            '<div class="omeka-dip-embla__controls">' +
            '<button type="button" class="omeka-dip-embla__prev button secondary" aria-label="Previous">‹</button>' +
            '<button type="button" class="omeka-dip-embla__next button secondary" aria-label="Next">›</button>' +
            '</div>';
        var container = embla.querySelector('.omeka-dip-embla__container');

        entries.forEach(function (entry) {
            container.appendChild(makeGallerySlide(entry));
        });

        section.appendChild(embla);
        appendGalleryDialog(section);
        embeds.appendChild(section);
    }

    // Open an image in its gallery's lightbox (the package tree uses this instead of downloading).
    function openGalleryKey(key) {
        var roots = document.querySelectorAll('.omeka-dip-gallery');
        for (var i = 0; i < roots.length; i++) {
            var root = roots[i];
            var entries = getGalleryIndex(root);
            for (var j = 0; j < entries.length; j++) {
                if (entries[j].key !== key) {
                    continue;
                }
                var dialog = root.querySelector('.omeka-dip-gallery-dialog');
                var fullImg = root.querySelector('.omeka-dip-gallery-full');
                var caption = root.querySelector('.omeka-dip-gallery-caption');
                if (!dialog || !fullImg || !dialog.showModal) {
                    return false;
                }
                showLightboxAtIndex(root, j, dialog, fullImg, caption);
                return true;
            }
        }
        return false;
    }

    function bindPackageTree() {
        // "Expand all" / "Collapse all" above a package tree.
        document.addEventListener('click', function (event) {
            var toggle = event.target.closest('[data-dip-tree-open]');
            if (!toggle) {
                return;
            }
            var open = toggle.getAttribute('data-dip-tree-open') === '1';
            var aside = toggle.closest('.omeka-dip-package__contents') || document;
            aside.querySelectorAll('details.omeka-dip-folder-details').forEach(function (d) {
                d.open = open;
            });
        });
        document.addEventListener('click', function (event) {
            var link = event.target.closest('a[data-dip-open]');
            if (!link || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
                return;
            }
            var kind = link.getAttribute('data-dip-open');
            var key = link.getAttribute('data-dip-key') || '';
            var opened = false;
            if (kind === 'image') {
                opened = openGalleryKey(key);
            } else if (kind === 'video' && window.OmekaDipVideos) {
                opened = window.OmekaDipVideos.play(key);
            }
            if (opened) {
                event.preventDefault();
            }
        });
    }

    window.OmekaDipGallery = { openKey: openGalleryKey };

    function boot() {
        bindPackageTree();
        listDiscContentsLast();
        mergeNativeMediaIntoDipGallery();
        buildNativeMediaGalleryFromEmbeds();
        document.querySelectorAll('.omeka-dip-gallery').forEach(initGallery);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
