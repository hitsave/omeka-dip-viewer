<?php
namespace OmekaDipViewer\Service;

/**
 * Disk cache for generated browse/search previews (PNG collage or JPEG fallback).
 */
class DipPreviewCache
{
    protected DipConfig $dipConfig;

    public function __construct(DipConfig $dipConfig)
    {
        $this->dipConfig = $dipConfig;
    }

    public function collagePath(int $mediaId, string $versionKey): string
    {
        $dir = $this->mediaDir($mediaId);

        return $dir . '/collage-' . $versionKey . '.png';
    }

    public function fallbackPath(int $mediaId, string $fileKey, string $versionKey): string
    {
        $dir = $this->mediaDir($mediaId);
        $hash = hash('sha256', $fileKey);

        return $dir . '/fallback-' . $hash . '-' . $versionKey . '.jpg';
    }

    public function isFresh(string $cachePath, string $tarPath): bool
    {
        if (!is_readable($cachePath)) {
            return false;
        }
        $tarMtime = @filemtime($tarPath) ?: 0;
        $cacheMtime = @filemtime($cachePath) ?: 0;

        return $cacheMtime >= $tarMtime;
    }

    protected function mediaDir(int $mediaId): string
    {
        $base = $this->dipConfig->getPreviewCacheDirectory();
        $dir = $base . '/' . $mediaId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create DIP preview cache directory.');
        }

        return $dir;
    }
}
