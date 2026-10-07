<?php
namespace OmekaDipViewer\Media\Renderer;

use OmekaDipViewer\Service\DipConfig;
use OmekaDipViewer\Service\DipGalleryFilter;
use OmekaDipViewer\Service\DipVideoFilter;
use OmekaDipViewer\Service\DipIndexService;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Media\Renderer\RendererInterface;
use Laminas\View\Renderer\PhpRenderer;

class DipPackage implements RendererInterface
{
    protected DipIndexService $dipIndexService;
    protected DipConfig $dipConfig;
    protected DipGalleryFilter $galleryFilter;
    protected DipVideoFilter $videoFilter;

    public function __construct(
        DipIndexService $dipIndexService,
        DipConfig $dipConfig,
        DipGalleryFilter $galleryFilter,
        DipVideoFilter $videoFilter
    ) {
        $this->dipIndexService = $dipIndexService;
        $this->dipConfig = $dipConfig;
        $this->galleryFilter = $galleryFilter;
        $this->videoFilter = $videoFilter;
    }

    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = [])
    {
        $siteSlug = isset($options['omeka_dip_site_slug'])
            ? (string) $options['omeka_dip_site_slug']
            : $this->resolveSiteSlug($view);
        if ($siteSlug === '') {
            $siteSlug = null;
        }

        $data = $media->mediaData();
        $index = $this->dipIndexService->getIndex($media);
        if (!$index) {
            return '<p class="omeka-dip-error">' . $view->escapeHtml($view->translate('Could not read DIP package index from stored archive.')) . '</p>';
        }

        $galleryFiles = $this->galleryFilter->filterGalleryFiles(
            $index['files'] ?? [],
            $this->dipConfig->getGalleryMaxBytes(),
            $this->dipConfig->getGalleryMimeTypes()
        );
        $galleryMaxDisplay = $this->dipConfig->getGalleryMaxDisplay();
        $videoFiles = [];
        if ($this->dipConfig->isVideoPlayerEnabled()) {
            $videoFiles = $this->videoFilter->filterPlaybackFiles($index['files'] ?? []);
        }

        return $view->partial('omeka-dip-viewer/media-render', [
            'media' => $media,
            'index' => $index,
            'galleryFiles' => $galleryFiles,
            'videoFiles' => $videoFiles,
            'galleryMaxDisplay' => $galleryMaxDisplay,
            'galleryMaxBytes' => $this->dipConfig->getGalleryMaxBytes(),
            'largePackage' => !empty($data['large_package']),
            'dipSizeBytes' => (int) ($data['dip_size_bytes'] ?? 0),
            'thresholdBytes' => (int) ($data['large_package_threshold_bytes'] ?? 0),
            'siteSlug' => $siteSlug,
        ]);
    }

    protected function resolveSiteSlug(PhpRenderer $view): ?string
    {
        $site = $view->site ?? null;
        if (is_object($site) && method_exists($site, 'slug')) {
            return (string) $site->slug();
        }
        if (method_exists($view, 'vars')) {
            $site = $view->vars('site');
            if (is_object($site) && method_exists($site, 'slug')) {
                return (string) $site->slug();
            }
        }

        return null;
    }
}
