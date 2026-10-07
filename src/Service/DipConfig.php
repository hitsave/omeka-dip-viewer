<?php
namespace OmekaDipViewer\Service;

use Omeka\Settings\SettingsInterface;

class DipConfig
{
    public const DEFAULT_THRESHOLD_BYTES = 524288000; // 500 MiB
    public const DEFAULT_GALLERY_MAX_BYTES = 52428800; // 50 MiB
    public const DEFAULT_GALLERY_MAX_DISPLAY = 48;
    public const DEFAULT_BROWSE_PREVIEW_MAX_BYTES = 10485760; // 10 MiB per collage member
    public const DEFAULT_BROWSE_PREVIEW_COLLAGE_MIN = 4;
    public const DEFAULT_BROWSE_PREVIEW_COLLAGE_MAX = 8;

    protected SettingsInterface $settings;

    public function __construct(SettingsInterface $settings)
    {
        $this->settings = $settings;
    }

    public function getLargePackageThresholdBytes(): int
    {
        return $this->intSetting('large_package_threshold_bytes', self::DEFAULT_THRESHOLD_BYTES);
    }

    public function blockLargePackages(): bool
    {
        return $this->boolSetting('block_large_packages', false);
    }

    public function isIndexCacheEnabled(): bool
    {
        return $this->boolSetting('index_cache_enabled', true);
    }

    public function getGalleryMaxBytes(): int
    {
        return $this->intSetting('gallery_max_bytes', self::DEFAULT_GALLERY_MAX_BYTES);
    }

    /**
     * @return list<string>
     */
    public function getGalleryMaxDisplay(): int
    {
        return max(0, $this->intSetting('gallery_max_display', self::DEFAULT_GALLERY_MAX_DISPLAY));
    }

    /**
     * @return list<string>
     */
    public function getGalleryMimeTypes(): array
    {
        $fromSetting = $this->getModuleSetting('gallery_mime_types');
        if (is_array($fromSetting) && $fromSetting !== []) {
            return array_values(array_map('strval', $fromSetting));
        }

        return DipGalleryFilter::DEFAULT_MIME_TYPES;
    }

    public function getIndexCacheDirectory(): string
    {
        $fromSetting = $this->getModuleSetting('index_cache_directory');
        if ($fromSetting) {
            return (string) $fromSetting;
        }

        return $this->resolvePathUnderOmekaRoot('data/dip_index_cache');
    }

    public function isVideoPlayerEnabled(): bool
    {
        return $this->boolSetting('video_player_enabled', true);
    }

    public function getStreamCacheDirectory(): string
    {
        $fromSetting = $this->getModuleSetting('stream_cache_directory');
        if ($fromSetting) {
            return (string) $fromSetting;
        }

        return $this->resolvePathUnderOmekaRoot('data/dip_stream_cache');
    }

    public function isBrowsePreviewEnabled(): bool
    {
        return $this->boolSetting('browse_preview_enabled', true);
    }

    public function getBrowsePreviewMaxBytesPerImage(): int
    {
        return $this->intSetting('browse_preview_max_bytes_per_image', self::DEFAULT_BROWSE_PREVIEW_MAX_BYTES);
    }

    public function getBrowsePreviewCollageMinImages(): int
    {
        return max(1, $this->intSetting('browse_preview_collage_min_images', self::DEFAULT_BROWSE_PREVIEW_COLLAGE_MIN));
    }

    public function getBrowsePreviewCollageMaxImages(): int
    {
        return max(1, $this->intSetting('browse_preview_collage_max_images', self::DEFAULT_BROWSE_PREVIEW_COLLAGE_MAX));
    }

    public function getPreviewCacheDirectory(): string
    {
        $fromSetting = $this->getModuleSetting('preview_cache_directory');
        if ($fromSetting) {
            return (string) $fromSetting;
        }

        return $this->resolvePathUnderOmekaRoot('data/dip_preview_cache');
    }

    protected function resolvePathUnderOmekaRoot(string $configured): string
    {
        if ($configured !== '' && $configured[0] === '/') {
            return $configured;
        }
        $root = defined('OMEKA_PATH') ? OMEKA_PATH : dirname(__DIR__, 4);

        return rtrim($root, '/') . '/' . ltrim($configured, '/');
    }

    protected function intSetting(string $suffix, int $default): int
    {
        $value = $this->getModuleSetting($suffix);
        if ($value !== null && $value !== '') {
            return (int) $value;
        }

        return $default;
    }

    protected function boolSetting(string $suffix, bool $default): bool
    {
        $value = $this->getModuleSetting($suffix);
        if ($value !== null) {
            return (bool) $value;
        }

        return $default;
    }

    /**
     * @return mixed
     */
    protected function getModuleSetting(string $suffix)
    {
        return $this->settings->get('omeka_dip_viewer.' . $suffix);
    }
}
