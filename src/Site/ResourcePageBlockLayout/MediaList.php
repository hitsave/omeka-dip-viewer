<?php
namespace OmekaDipViewer\Site\ResourcePageBlockLayout;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Site\ResourcePageBlockLayout\ResourcePageBlockLayoutInterface;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Wraps core media list; passes render pass so DIP packages render once (full_width_main).
 */
class MediaList implements ResourcePageBlockLayoutInterface
{
    private static int $renderPass = 0;

    public function getLabel(): string
    {
        return 'Media list'; // @translate
    }

    public function getCompatibleResourceNames(): array
    {
        return ['items'];
    }

    public function render(PhpRenderer $view, AbstractResourceEntityRepresentation $resource): string
    {
        self::$renderPass++;

        return $view->partial('common/resource-page-block-layout/media-list', [
            'resource' => $resource,
            'omekaDipMediaListPass' => self::$renderPass,
        ]);
    }
}
