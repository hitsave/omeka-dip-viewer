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

    function cancelProgressLoad(img) {
        if (!img) {
            return;
        }
        if (img._dipLoadAbort) {
            img._dipLoadAbort.abort();
            img._dipLoadAbort = null;
        }
    }

    function revokeBlobUrl(img) {
        if (img && img._dipBlobUrl) {
            URL.revokeObjectURL(img._dipBlobUrl);
            img._dipBlobUrl = null;
        }
    }

    /**
     * Fetch full image with download progress (lightbox). Falls back to loadImage on failure.
     */
    function loadImageWithProgress(img, url, options) {
        options = options || {};
        if (!img || !url) {
            return;
        }
        cancelProgressLoad(img);
        img.setAttribute('data-dip-src', url);
        img.removeAttribute('data-dip-loaded');
        img.removeAttribute('data-dip-queued');

        var onProgress = typeof options.onProgress === 'function' ? options.onProgress : null;
        var onComplete = typeof options.onComplete === 'function' ? options.onComplete : null;
        var onError = typeof options.onError === 'function' ? options.onError : null;

        var ac = new AbortController();
        img._dipLoadAbort = ac;

        fetch(url, { credentials: 'same-origin', signal: ac.signal })
            .then(function (resp) {
                if (!resp.ok) {
                    throw new Error('HTTP ' + resp.status);
                }
                var total = parseInt(resp.headers.get('Content-Length') || '0', 10);
                if (!resp.body || total <= 0) {
                    if (onProgress) {
                        onProgress(null);
                    }
                    return resp.blob();
                }
                var loaded = 0;
                var reader = resp.body.getReader();
                var chunks = [];

                function pump() {
                    return reader.read().then(function (result) {
                        if (result.done) {
                            return new Blob(chunks);
                        }
                        chunks.push(result.value);
                        loaded += result.value.byteLength;
                        if (onProgress) {
                            onProgress(Math.min(100, Math.round((loaded * 100) / total)));
                        }
                        return pump();
                    });
                }

                return pump();
            })
            .then(function (blob) {
                if (ac.signal.aborted) {
                    return;
                }
                revokeBlobUrl(img);
                img._dipBlobUrl = URL.createObjectURL(blob);
                img.src = img._dipBlobUrl;
                img.dataset.dipLoaded = '1';
                img._dipLoadAbort = null;
                if (onComplete) {
                    onComplete();
                }
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') {
                    return;
                }
                img._dipLoadAbort = null;
                if (onError) {
                    onError(err);
                    return;
                }
                img.onload = function () {
                    img.dataset.dipLoaded = '1';
                    img.removeAttribute('data-dip-queued');
                    if (onComplete) {
                        onComplete();
                    }
                };
                img.onerror = function () {
                    if (onComplete) {
                        onComplete();
                    }
                };
                img.src = url;
            });
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
        loadImageWithProgress: loadImageWithProgress,
        cancelProgressLoad: cancelProgressLoad,
        revokeBlobUrl: revokeBlobUrl,
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
