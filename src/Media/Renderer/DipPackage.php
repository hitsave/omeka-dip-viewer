<?php
namespace OmekaDipViewer\Media\Renderer;

use OmekaDipViewer\Service\DipCatalogPolicy;
use OmekaDipViewer\Service\DipConfig;
use OmekaDipViewer\Service\DipGalleryFilter;
use OmekaDipViewer\Service\DipVideoFilter;
use OmekaDipViewer\Service\DipIndexService;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Media\Renderer\RendererInterface;
use Laminas\View\Renderer\PhpRenderer;

class DipPackage implements RendererInterface
{
    /** @var array<int, true> */
    private static array $renderedMediaIds = [];

    protected DipIndexService $dipIndexService;
    protected DipConfig $dipConfig;
    protected DipGalleryFilter $galleryFilter;
    protected DipVideoFilter $videoFilter;
    protected DipCatalogPolicy $catalogPolicy;

    public function __construct(
        DipIndexService $dipIndexService,
        DipConfig $dipConfig,
        DipGalleryFilter $galleryFilter,
        DipVideoFilter $videoFilter,
        DipCatalogPolicy $catalogPolicy
    ) {
        $this->dipIndexService = $dipIndexService;
        $this->dipConfig = $dipConfig;
        $this->galleryFilter = $galleryFilter;
        $this->videoFilter = $videoFilter;
        $this->catalogPolicy = $catalogPolicy;
    }

    /**
     * Original URLs of the item's other (file) media that are byte-identical (SHA-256) to a file
     * shown in this package's gallery, e.g. catalogue disc photos that are also scans in the bag.
     * The gallery script leaves those out when it folds file media into the package gallery.
     * Only reads the bag manifest when the item actually has file media.
     *
     * @param array<string, mixed> $index
     * @param list<string> $galleryKeys
     * @return list<string>
     */
    protected function duplicateFileMediaOriginals(MediaRepresentation $media, array $index, array $galleryKeys): array
    {
        $fileMedia = [];
        foreach ($media->item()->media() as $other) {
            if ((int) $other->id() === (int) $media->id() || !$other->hasOriginal() || !$other->sha256()) {
                continue;
            }
            $fileMedia[] = $other;
        }
        if (!$fileMedia || !$galleryKeys) {
            return [];
        }
        $pathByKey = [];
        foreach ($index['files'] ?? [] as $file) {
            if (isset($file['key'], $file['inner_path'])) {
                $pathByKey[(string) $file['key']] = (string) $file['inner_path'];
            }
        }
        $shaByPath = $this->dipIndexService->payloadSha256($media);
        $gallerySha = [];
        foreach ($galleryKeys as $key) {
            $path = $pathByKey[(string) $key] ?? null;
            if ($path !== null && isset($shaByPath[$path])) {
                $gallerySha[$shaByPath[$path]] = true;
            }
        }
        $out = [];
        foreach ($fileMedia as $other) {
            if (isset($gallerySha[strtolower((string) $other->sha256())])) {
                $out[] = (string) $other->originalUrl();
            }
        }

        return $out;
    }

    public function render(PhpRenderer $view, MediaRepresentation $media, array $options = [])
    {
        $mediaId = (int) $media->id();
        if (isset(self::$renderedMediaIds[$mediaId])) {
            return '';
        }
        self::$renderedMediaIds[$mediaId] = true;

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

        $previewManifest = $this->dipIndexService->getPreviewManifest($media);
        $preservationDump = $this->catalogPolicy->isPreservationDump($media);

        $galleryFiles = [];
        $videoFiles = [];
        $packagedPreviewKeys = [];
        $packagedPreviewLabels = [];
        $scanFiles = [];
        if ($preservationDump) {
            $packagedPreviewKeys = $this->catalogPolicy->packagedPreviewFileKeys($previewManifest);
            foreach ($packagedPreviewKeys as $key) {
                $packagedPreviewLabels[$key] = $this->catalogPolicy->galleryLabel($previewManifest, (string) $key);
            }
            $scanFiles = $this->catalogPolicy->scanFiles($previewManifest);
            $duplicateOriginals = $this->duplicateFileMediaOriginals($media, $index, $packagedPreviewKeys);
        } else {
            $galleryFiles = $this->galleryFilter->filterGalleryFiles(
                $index['files'] ?? [],
                $this->dipConfig->getGalleryMaxBytes(),
                $this->dipConfig->getGalleryMimeTypes()
            );
            if ($this->dipConfig->isVideoPlayerEnabled()) {
                $videoFiles = $this->videoFilter->filterPlaybackFiles($index['files'] ?? []);
            }
            $documentFiles = $this->documentFiles($index['files'] ?? []);
        }
        // Files the package tree opens in the viewer instead of downloading.
        $openInViewer = [];
        foreach ($galleryFiles as $file) {
            $openInViewer[(string) $file['key']] = 'image';
        }
        foreach ($videoFiles as $file) {
            $openInViewer[(string) $file['key']] = 'video';
        }
        foreach ($documentFiles ?? [] as $file) {
            $openInViewer[(string) $file['key']] = 'document';
        }
        $galleryMaxDisplay = $this->dipConfig->getGalleryMaxDisplay();

        return $view->partial('omeka-dip-viewer/media-render', [
            'media' => $media,
            'index' => $index,
            'previewManifest' => $previewManifest,
            'galleryFiles' => $galleryFiles,
            'videoFiles' => $videoFiles,
            'documentFiles' => $documentFiles ?? [],
            'openInViewer' => $openInViewer,
            'packagedPreviewKeys' => $packagedPreviewKeys,
            'packagedPreviewLabels' => $packagedPreviewLabels,
            'scanFiles' => $scanFiles,
            'duplicateOriginals' => $duplicateOriginals ?? [],
            'showPackageContents' => !$preservationDump,
            'galleryMaxDisplay' => $galleryMaxDisplay,
            'galleryMaxBytes' => $this->dipConfig->getGalleryMaxBytes(),
            'largePackage' => !empty($data['large_package']),
            'dipSizeBytes' => (int) ($data['dip_size_bytes'] ?? 0),
            'thresholdBytes' => (int) ($data['large_package_threshold_bytes'] ?? 0),
            'siteSlug' => $siteSlug,
        ]);
    }

    /**
     * PDFs, and documents with a PDF web copy (disc contents: Word/RTF/WRI converted at packaging).
     *
     * @param list<array<string, mixed>> $files
     * @return list<array<string, mixed>>
     */
    protected function documentFiles(array $files): array
    {
        $out = [];
        foreach ($files as $file) {
            $mime = strtolower((string) ($file['mime'] ?? ''));
            $accessMime = strtolower((string) ($file['access_mime'] ?? ''));
            if ($accessMime === 'application/pdf' || $mime === 'application/pdf') {
                $out[] = $file;
            }
        }

        return $out;
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
