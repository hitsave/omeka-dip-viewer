<?php
namespace OmekaDipViewer\ViewHelper;

use Omeka\Api\Representation\ItemRepresentation;
use OmekaDipViewer\Service\DipBrowsePreviewService;
use Laminas\View\Helper\AbstractHtmlElement;

/**
 * Thumbnail for item browse/search when the item is a DIP without a custom cover.
 */
class DipBrowseThumbnail extends AbstractHtmlElement
{
    protected DipBrowsePreviewService $browsePreview;

    public function __construct(DipBrowsePreviewService $browsePreview)
    {
        $this->browsePreview = $browsePreview;
    }

    /**
     * @param array $attribs
     */
    public function __invoke(ItemRepresentation $item, string $type = 'medium', array $attribs = []): string
    {
        $thumbnail = $this->getView()->plugin('thumbnail');
        if (!$this->browsePreview->hasBrowsePreview($item)) {
            return $thumbnail($item, $type, $attribs);
        }

        $url = $this->browsePreview->getPreviewUrl($item, $type);
        if ($url === null) {
            return $thumbnail($item, $type, $attribs);
        }

        $attribs['src'] = $url;
        if (!isset($attribs['class'])) {
            $attribs['class'] = 'thumbnail omeka-dip-browse-preview';
        } elseif (strpos((string) $attribs['class'], 'omeka-dip-browse-preview') === false) {
            $attribs['class'] .= ' omeka-dip-browse-preview';
        }

        if (!isset($attribs['alt'])) {
            $attribs['alt'] = $item->thumbnailAltText();
        }

        return sprintf('<img%s>', $this->htmlAttribs($attribs));
    }
}
