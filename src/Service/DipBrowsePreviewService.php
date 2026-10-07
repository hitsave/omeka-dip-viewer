<?php
namespace OmekaDipViewer\Service;

use OmekaDipViewer\Media\DipPackageMedia;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\MediaRepresentation;

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

    public function __construct(
        DipIndexService $indexService,
        DipConfig $dipConfig,
        DipGalleryFilter $galleryFilter,
        DipPreviewCache $previewCache,
        DipCollageBuilder $collageBuilder,
        MetsParser $metsParser
    ) {
        $this->indexService = $indexService;
        $this->dipConfig = $dipConfig;
        $this->galleryFilter = $galleryFilter;
        $this->previewCache = $previewCache;
        $this->collageBuilder = $collageBuilder;
        $this->metsParser = $metsParser;
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
        $media = $this->findDipMedia($item);
        if (!$media || $item->thumbnail()) {
            return false;
        }

        return $this->listGalleryFiles($media) !== [];
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

        $tarPath = $this->indexService->resolveTarPath($media);
        if (!$tarPath) {
            throw new \RuntimeException('DIP archive path not found.');
        }

        $galleryFiles = $this->listGalleryFiles($media);
        if ($galleryFiles === []) {
            throw new \RuntimeException('No gallery-eligible images in DIP.');
        }

        $versionKey = $this->versionKey($type);
        $minCollage = $this->dipConfig->getBrowsePreviewCollageMinImages();
        if (count($galleryFiles) >= $minCollage) {
            $cachePath = $this->previewCache->collagePath((int) $media->id(), $versionKey);
            if (!$this->previewCache->isFresh($cachePath, $tarPath)) {
                $this->buildCollage($media, $galleryFiles, $cachePath, $type, $tarPath);
            }

            return [$cachePath, 'image/png'];
        }

        $file = $galleryFiles[0];
        $cachePath = $this->previewCache->fallbackPath((int) $media->id(), (string) $file['key'], $versionKey);
        if (!$this->previewCache->isFresh($cachePath, $tarPath)) {
            $this->buildSingleFallback($media, $file, $cachePath, $type, $tarPath);
        }

        return [$cachePath, 'image/jpeg'];
    }

    /**
     * @return list<array<string, mixed>>
     */
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
    protected function buildCollage(
        MediaRepresentation $media,
        array $galleryFiles,
        string $cachePath,
        string $type,
        string $tarPath
    ): void {
        $itemId = (int) $media->item()->id();
        $mediaId = (int) $media->id();
        $pickCount = $this->pickCollageCount(count($galleryFiles), $itemId, $mediaId);
        $picked = $this->pickFiles($galleryFiles, $pickCount, $itemId, $mediaId);

        $gdImages = [];
        foreach ($picked as $file) {
            $img = $this->loadGalleryImage($tarPath, $file);
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
            $this->buildSingleFallback($media, $galleryFiles[0], $cachePath, $type, $tarPath);

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
        string $tarPath
    ): void {
        $img = $this->loadGalleryImage($tarPath, $file);
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
    protected function loadGalleryImage(string $tarPath, array $file): ?\GdImage
    {
        $innerPath = $file['inner_path'] ?? '';
        if ($innerPath === '') {
            return null;
        }
        try {
            $bytes = $this->metsParser->readTarMember($tarPath, $innerPath);
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
