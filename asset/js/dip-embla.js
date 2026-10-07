(function () {
    'use strict';

    function wheelPlugins(viewport) {
        if (typeof EmblaCarouselWheelGestures !== 'function') {
            return [];
        }
        return [
            EmblaCarouselWheelGestures({
                forceWheelAxis: 'x',
                target: viewport,
            }),
        ];
    }

    window.OmekaDipEmbla = {
        create: function (viewport, options) {
            if (typeof EmblaCarousel !== 'function') {
                return null;
            }
            return EmblaCarousel(viewport, options, wheelPlugins(viewport));
        },
    };
})();
