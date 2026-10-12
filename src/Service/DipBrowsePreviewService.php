<?php
namespace OmekaDipViewer\Service;

use OmekaDipViewer\Media\DipPackageMedia;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Laminas\View\Renderer\PhpRenderer;

/**
 * Browse/search preview images: cached collage (default) or single gallery image (fallback).
 */
class DipBrowsePreviewService
{
    protected DipIndexService $indexService;
    protected DipConfig $dipConfig;
    protected DipGalleryFilter $galleryFilter;
    protected DipPreviewCache $previewCache;
    protected DipCollageBuilder $collageBuilder;
    protected MetsParser $metsParser;
    protected DipS3ObjectStore $s3;

    public function __construct(
        DipIndexService $indexService,
        DipConfig $dipConfig,
        DipGalleryFilter $galleryFilter,
        DipPreviewCache $previewCache,
        DipCollageBuilder $collageBuilder,
        MetsParser $metsParser,
        DipS3ObjectStore $s3
    ) {
        $this->indexService = $indexService;
        $this->dipConfig = $dipConfig;
        $this->galleryFilter = $galleryFilter;
        $this->previewCache = $previewCache;
        $this->collageBuilder = $collageBuilder;
        $this->metsParser = $metsParser;
        $this->s3 = $s3;
    }

    /**
     * Site pages with browsePreview blocks render thumbnails before controller view events
     * run; register the queue loader whenever we emit a deferred DIP browse img.
     */
    public function registerMediaQueueScript(PhpRenderer $view): void
    {
        static $registered = false;
        if ($registered || !$this->dipConfig->isBrowsePreviewEnabled()) {
            return;
        }
        $view->headScript()->appendFile(
            $view->assetUrl('js/dip-media-queue.js', 'OmekaDipViewer'),
            'text/javascript',
            ['defer' => true]
        );
        $registered = true;
    }

    public function getPreviewUrl(ItemRepresentation $item, string $type = 'medium'): ?string
    {
        if (!$this->hasBrowsePreview($item)) {
            return null;
        }
        $media = $this->findDipMedia($item);
        if (!$media) {
            return null;
        }

        $version = $this->versionKey($type);

        return '/omeka-dip/browse-preview/' . (int) $media->id()
            . '?type=' . rawurlencode($type)
            . '&v=' . rawurlencode($version);
    }

    public function hasBrowsePreview(ItemRepresentation $item): bool
    {
        if (!$this->dipConfig->isBrowsePreviewEnabled()) {
            return false;
        }
        if ($item->thumbnail()) {
            return false;
        }
        $media = $this->findDipMedia($item);
        if (!$media) {
            return false;
        }
        // Do not index the tar during page render (browse hub lists many items).
        // Preview bytes are built on GET /omeka-dip/browse-preview/{media_id}.
        return $this->indexService->packageIsAvailable($media);
    }

    public function findDipMedia(ItemRepresentation $item): ?MediaRepresentation
    {
        $primary = $item->primaryMedia();
        if ($primary && DipPackageMedia::isDipPackage($primary->renderer())) {
            return $primary;
        }
        foreach ($item->media() as $media) {
            if (DipPackageMedia::isDipPackage($media->renderer())) {
                return $media;
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string} absolute path and mime
     */
    public function resolvePreviewFile(MediaRepresentation $media, string $type): array
    {
        if (!DipPackageMedia::isDipPackage($media->renderer())) {
            throw new \InvalidArgumentException('Media is not a DIP package.');
        }

        $s3Prefix = $this->indexService->resolveS3Prefix($media);
        if ($s3Prefix === null) {
            throw new \RuntimeException('DIP package storage not found.');
        }
        $tarPath = null;

        $manifest = $this->indexService->getPreviewManifest($media);
        $typeKey = preg_replace('/[^a-z0-9_-]/i', '', $type) ?: 'medium';
        if (is_array($manifest) && !empty($manifest['browse'][$typeKey])) {
            return $this->resolvePackagedBrowseFile(
                $media,
                $s3Prefix,
                $tarPath,
                (string) $manifest['browse'][$typeKey]
            );
        }

        $galleryFiles = $this->listGalleryFiles($media);
        if ($galleryFiles === []) {
            throw new \RuntimeException('No gallery-eligible images in DIP.');
        }

        $versionKey = $this->versionKey($type);
        $minCollage = $this->dipConfig->getBrowsePreviewCollageMinImages();
        if (count($galleryFiles) >= $minCollage) {
            $cachePath = $this->previewCache->collagePath((int) $media->id(), $versionKey);
            if ($this->previewCacheNeedsRebuild($cachePath, $tarPath)) {
                $this->buildCollage($media, $galleryFiles, $cachePath, $type, $tarPath, $s3Prefix);
            }

            return [$cachePath, 'image/png'];
        }

        $file = $galleryFiles[0];
        $cachePath = $this->previewCache->fallbackPath((int) $media->id(), (string) $file['key'], $versionKey);
        if ($this->previewCacheNeedsRebuild($cachePath, $tarPath)) {
            $this->buildSingleFallback($media, $file, $cachePath, $type, $tarPath, $s3Prefix);
        }

        return [$cachePath, 'image/jpeg'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * @return array{0: string, 1: string}
     */
    protected function resolvePackagedBrowseFile(
        MediaRepresentation $media,
        ?string $s3Prefix,
        ?string $tarPath,
        string $innerPath
    ): array {
        $mediaId = (int) $media->id();
        $cachePath = $this->previewCache->packagedExtractPath($mediaId, $innerPath);
        if (!is_readable($cachePath)) {
            if ($s3Prefix !== null) {
                $bytes = $this->s3->getObject($s3Prefix, $innerPath);
                $dir = dirname($cachePath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($cachePath, $bytes);
            }
        }
        $mime = str_ends_with(strtolower($innerPath), '.png') ? 'image/png' : 'image/jpeg';

        return [$cachePath, $mime];
    }

    protected function listGalleryFiles(MediaRepresentation $media): array
    {
        $index = $this->indexService->getIndex($media);
        if (!$index) {
            return [];
        }

        return $this->galleryFilter->filterGalleryFiles(
            $index['files'] ?? [],
            $this->dipConfig->getBrowsePreviewMaxBytesPerImage(),
            $this->dipConfig->getGalleryMimeTypes()
        );
    }

    /**
     * @param list<array<string, mixed>> $galleryFiles
     */
    protected function previewCacheNeedsRebuild(string $cachePath, ?string $tarPath): bool
    {
        if (!is_readable($cachePath)) {
            return true;
        }
        if ($tarPath !== null && $tarPath !== '') {
            return !$this->previewCache->isFresh($cachePath, $tarPath);
        }

        return false;
    }

    protected function buildCollage(
        MediaRepresentation $media,
        array $galleryFiles,
        string $cachePath,
        string $type,
        ?string $tarPath,
        ?string $s3Prefix = null
    ): void {
        $itemId = (int) $media->item()->id();
        $mediaId = (int) $media->id();
        $pickCount = $this->pickCollageCount(count($galleryFiles), $itemId, $mediaId);
        $picked = $this->pickFiles($galleryFiles, $pickCount, $itemId, $mediaId);

        $gdImages = [];
        foreach ($picked as $file) {
            $img = $this->loadGalleryImage($tarPath, $s3Prefix, $file);
            if ($img !== null) {
                $gdImages[] = $img;
            }
        }
        if (count($gdImages) < $this->dipConfig->getBrowsePreviewCollageMinImages()) {
            foreach ($gdImages as $img) {
                imagedestroy($img);
            }
            if ($galleryFiles === []) {
                throw new \RuntimeException('Could not load collage images.');
            }
            $this->buildSingleFallback($media, $galleryFiles[0], $cachePath, $type, $tarPath, $s3Prefix);

            return;
        }

        [$canvasW, $canvasH] = $this->canvasSizeForType($type);
        $collage = $this->collageBuilder->build($gdImages, $canvasW, $canvasH);
        foreach ($gdImages as $img) {
            imagedestroy($img);
        }
        $this->writePng($collage, $cachePath);
        imagedestroy($collage);
    }

    /**
     * @param array<string, mixed> $file
     */
    protected function buildSingleFallback(
        MediaRepresentation $media,
        array $file,
        string $cachePath,
        string $type,
        ?string $tarPath,
        ?string $s3Prefix = null
    ): void {
        $img = $this->loadGalleryImage($tarPath, $s3Prefix, $file);
        if ($img === null) {
            throw new \RuntimeException('Could not load fallback image from DIP.');
        }
        [$maxW, $maxH] = $this->maxBoundsForType($type);
        $scaled = $this->collageBuilder->scaleToFit($img, $maxW, $maxH);
        imagedestroy($img);
        $this->writeJpeg($scaled, $cachePath, 88);
        imagedestroy($scaled);
    }

    /**
     * @param array<string, mixed> $file
     */
    protected function loadGalleryImage(?string $tarPath, ?string $s3Prefix, array $file): ?\GdImage
    {
        $innerPath = $file['inner_path'] ?? '';
        if ($innerPath === '') {
            return null;
        }
        try {
            if ($s3Prefix === null || $s3Prefix === '') {
                return null;
            }
            $bytes = $this->s3->getObject($s3Prefix, $innerPath);
        } catch (\Throwable $e) {
            return null;
        }
        if ($bytes === '') {
            return null;
        }
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            return null;
        }

        return $img;
    }

    protected function writeJpeg(\GdImage $image, string $path, int $quality): void
    {
        $partial = $path . '.partial';
        if (!imagejpeg($image, $partial, $quality)) {
            @unlink($partial);
            throw new \RuntimeException('Failed to encode preview JPEG.');
        }
        if (!rename($partial, $path)) {
            @unlink($partial);
            throw new \RuntimeException('Failed to write preview cache file.');
        }
    }

    protected function writePng(\GdImage $image, string $path): void
    {
        imagesavealpha($image, true);
        imagealphablending($image, false);
        $partial = $path . '.partial';
        if (!imagepng($image, $partial, 6)) {
            @unlink($partial);
            throw new \RuntimeException('Failed to encode preview PNG.');
        }
        if (!rename($partial, $path)) {
            @unlink($partial);
            throw new \RuntimeException('Failed to write preview cache file.');
        }
    }

    protected function pickCollageCount(int $available, int $itemId, int $mediaId): int
    {
        $min = $this->dipConfig->getBrowsePreviewCollageMinImages();
        $max = min($this->dipConfig->getBrowsePreviewCollageMaxImages(), $available);
        if ($available < $min) {
            return $available;
        }
        if ($max <= $min) {
            return $max;
        }
        $span = $max - $min + 1;
        $seed = crc32($itemId . ':' . $mediaId . ':collage-count');

        return $min + (int) (abs($seed) % $span);
    }

    /**
     * @param list<array<string, mixed>> $files
     * @return list<array<string, mixed>>
     */
    protected function pickFiles(array $files, int $count, int $itemId, int $mediaId): array
    {
        $files = array_values($files);
        $seed = crc32($itemId . ':' . $mediaId . ':collage-pick');
        mt_srand($seed);
        for ($i = count($files) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$files[$i], $files[$j]] = [$files[$j], $files[$i]];
        }
        mt_srand();

        return array_slice($files, 0, min($count, count($files)));
    }

    protected function versionKey(string $type): string
    {
        $type = preg_replace('/[^a-z0-9_-]/i', '', $type) ?: 'medium';

        return 'v4-grid-transparent-' . $type;
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function canvasSizeForType(string $type): array
    {
        return match ($type) {
            'square' => [400, 400],
            'large' => [960, 540],
            'medium' => [640, 480],
            default => [640, 480],
        };
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function maxBoundsForType(string $type): array
    {
        return match ($type) {
            'square' => [400, 400],
            'large' => [960, 960],
            'medium' => [640, 640],
            default => [640, 640],
        };
    }
}
