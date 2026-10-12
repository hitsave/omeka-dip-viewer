/**
 * Limit concurrent DIP asset fetches (gallery thumbs, browse previews) to avoid PHP-FPM stampedes.
 */
(function () {
    'use strict';

    var MAX_CONCURRENT = 3;
    var active = 0;
    var queue = [];
    var prefetchedUrls = new Set();

    function finish() {
        active = Math.max(0, active - 1);
        drain();
    }

    function startPrefetch(task) {
        fetch(task.url, { credentials: 'same-origin', cache: 'force-cache' })
            .catch(function () {
                /* warm stream cache even on opaque errors */
            })
            .finally(finish);
    }

    function startImageLoad(task) {
        if (task.prefetch) {
            startPrefetch(task);
            return;
        }
        var img = task.img;
        if (!img) {
            finish();
            return;
        }
        var url = task.url || img.getAttribute('data-dip-src');
        if (!url) {
            finish();
            return;
        }
        if (img.dataset.dipLoaded === '1' && img.src === url) {
            finish();
            return;
        }

        img.removeAttribute('data-dip-loaded');
        img.dataset.dipQueued = '1';
        img.onload = function () {
            img.dataset.dipLoaded = '1';
            img.removeAttribute('data-dip-queued');
            finish();
        };
        img.onerror = function () {
            img.removeAttribute('data-dip-queued');
            finish();
        };
        img.src = url;
    }

    function drain() {
        while (active < MAX_CONCURRENT && queue.length) {
            var task = queue.shift();
            active++;
            startImageLoad(task);
        }
    }

    function enqueue(task, priority) {
        var url = task.url || (task.img && task.img.getAttribute('data-dip-src'));
        if (
            task.img &&
            task.img.dataset.dipLoaded === '1' &&
            url &&
            task.img.src === url
        ) {
            return;
        }
        if (priority) {
            queue.unshift(task);
        } else {
            queue.push(task);
        }
        drain();
    }

    function attachImage(img) {
        if (!img || img.dataset.dipLoaded === '1' || img.dataset.dipQueued === '1') {
            return;
        }
        var url = img.getAttribute('data-dip-src');
        if (!url) {
            return;
        }
        enqueue({ img: img, url: url }, false);
    }

    function loadImage(img, url, options) {
        options = options || {};
        if (!img) {
            return;
        }
        var targetUrl = url || img.getAttribute('data-dip-src');
        if (!targetUrl) {
            return;
        }
        if (url) {
            img.setAttribute('data-dip-src', url);
        }
        if (img.dataset.dipLoaded === '1' && img.src === targetUrl) {
            return;
        }
        img.removeAttribute('data-dip-loaded');
        img.removeAttribute('data-dip-queued');
        enqueue({ img: img, url: targetUrl }, !!options.priority);
    }

    function scan(root) {
        var scope = root || document;
        scope.querySelectorAll('img[data-dip-src]:not([data-dip-loaded])').forEach(attachImage);
    }

    function prefetchUrl(url) {
        if (!url || prefetchedUrls.has(url)) {
            return;
        }
        prefetchedUrls.add(url);
        queue.push({ prefetch: true, url: url });
        drain();
    }

    window.OmekaDipMediaQueue = {
        attachImage: attachImage,
        loadImage: loadImage,
        prefetchUrl: prefetchUrl,
        scan: scan,
        setMaxConcurrent: function (n) {
            var parsed = parseInt(n, 10);
            if (parsed > 0) {
                MAX_CONCURRENT = parsed;
                drain();
            }
        },
    };

    function boot() {
        scan(document);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
