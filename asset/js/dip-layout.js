/**
 * Move DIP package file tree into the theme right column beside item metadata.
 */
(function () {
    'use strict';

    if (!document.body.classList.contains('item') || !document.body.classList.contains('resource')) {
        return;
    }

    var stack = document.querySelector('.stack');
    if (!stack) {
        return;
    }

    // Intended: mediaList in theme full_width_main. Prod may still embed media in .media-embeds inside main.
    var contents =
        stack.querySelector('.full-width-main .omeka-dip-package__contents') ||
        stack.querySelector('.media-embeds .omeka-dip-package__contents') ||
        stack.querySelector('.main-with-sidebar .omeka-dip-package__contents');
    var grid = stack.querySelector('.grid-x');
    var main = stack.querySelector('.main-with-sidebar');
    if (!contents || !grid || !main) {
        return;
    }

    var pane = stack.querySelector('.right-sidebar');
    if (!pane) {
        pane = document.createElement('div');
        pane.className = 'right-sidebar cell medium-4';
        grid.appendChild(pane);
    }

    pane.classList.add('omeka-dip-package-pane');
    pane.innerHTML = '';
    pane.appendChild(contents);
    document.body.classList.add('omeka-dip-item-layout');
})();
