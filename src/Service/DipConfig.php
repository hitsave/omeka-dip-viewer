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
    protected ?array $fileConfig = null;

    public function __construct(SettingsInterface $settings)
    {
        $this->settings = $settings;
    }

    public function getLargePackageThresholdBytes(): int
    {
        $fromSetting = $this->getModuleSetting('large_package_threshold_bytes');
        if ($fromSetting) {
            return (int) $fromSetting;
        }
        $file = $this->loadFileConfig();
        return (int) ($file['dip_viewer']['large_package_threshold_bytes'] ?? self::DEFAULT_THRESHOLD_BYTES);
    }

    public function blockLargePackages(): bool
    {
        $value = $this->getModuleSetting('block_large_packages');
        if ($value !== null) {
            return (bool) $value;
        }
        $file = $this->loadFileConfig();
        return (bool) ($file['dip_viewer']['block_large_packages'] ?? false);
    }

    public function isIndexCacheEnabled(): bool
    {
        $value = $this->getModuleSetting('index_cache_enabled');
        if ($value !== null) {
            return (bool) $value;
        }
        $file = $this->loadFileConfig();
        $cache = $file['dip_viewer']['index_cache'] ?? [];

        return (bool) ($cache['enabled'] ?? true);
    }

    public function getGalleryMaxBytes(): int
    {
        $fromSetting = $this->getModuleSetting('gallery_max_bytes');
        if ($fromSetting !== null && $fromSetting !== '') {
            return (int) $fromSetting;
        }
        $file = $this->loadFileConfig();
        $value = $file['dip_viewer']['gallery_max_bytes'] ?? self::DEFAULT_GALLERY_MAX_BYTES;

        return (int) $value;
    }

    /**
     * @return list<string>
     */
    public function getGalleryMaxDisplay(): int
    {
        $fromSetting = $this->getModuleSetting('gallery_max_display');
        if ($fromSetting !== null && $fromSetting !== '') {
            return max(0, (int) $fromSetting);
        }
        $file = $this->loadFileConfig();
        $value = $file['dip_viewer']['gallery_max_display'] ?? self::DEFAULT_GALLERY_MAX_DISPLAY;

        return max(0, (int) $value);
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
        $file = $this->loadFileConfig();
        $types = $file['dip_viewer']['gallery_mime_types'] ?? DipGalleryFilter::DEFAULT_MIME_TYPES;
        if (!is_array($types) || $types === []) {
            return DipGalleryFilter::DEFAULT_MIME_TYPES;
        }

        return array_values(array_map('strval', $types));
    }

    public function getIndexCacheDirectory(): string
    {
        $fromSetting = $this->getModuleSetting('index_cache_directory');
        if ($fromSetting) {
            return (string) $fromSetting;
        }
        $file = $this->loadFileConfig();
        $cache = $file['dip_viewer']['index_cache'] ?? [];

        return $this->resolvePathUnderOmekaRoot($cache['directory'] ?? 'data/dip_index_cache');
    }

    public function isVideoPlayerEnabled(): bool
    {
        $value = $this->getModuleSetting('video_player_enabled');
        if ($value !== null) {
            return (bool) $value;
        }
        $file = $this->loadFileConfig();
        $player = $file['dip_viewer']['video_player'] ?? [];

        return (bool) ($player['enabled'] ?? true);
    }

    public function getStreamCacheDirectory(): string
    {
        $fromSetting = $this->getModuleSetting('stream_cache_directory');
        if ($fromSetting) {
            return (string) $fromSetting;
        }
        $file = $this->loadFileConfig();
        $cache = $file['dip_viewer']['stream_cache'] ?? [];

        return $this->resolvePathUnderOmekaRoot($cache['directory'] ?? 'data/dip_stream_cache');
    }

    public function isBrowsePreviewEnabled(): bool
    {
        $value = $this->getModuleSetting('browse_preview_enabled');
        if ($value !== null) {
            return (bool) $value;
        }
        $file = $this->loadFileConfig();
        $preview = $file['dip_viewer']['browse_preview'] ?? [];

        return (bool) ($preview['enabled'] ?? true);
    }

    public function getBrowsePreviewMaxBytesPerImage(): int
    {
        $fromSetting = $this->getModuleSetting('browse_preview_max_bytes_per_image');
        if ($fromSetting !== null && $fromSetting !== '') {
            return (int) $fromSetting;
        }
        $file = $this->loadFileConfig();
        $preview = $file['dip_viewer']['browse_preview'] ?? [];
        $value = $preview['max_bytes_per_image'] ?? self::DEFAULT_BROWSE_PREVIEW_MAX_BYTES;

        return (int) $value;
    }

    public function getBrowsePreviewCollageMinImages(): int
    {
        $fromSetting = $this->getModuleSetting('browse_preview_collage_min_images');
        if ($fromSetting !== null && $fromSetting !== '') {
            return max(1, (int) $fromSetting);
        }
        $file = $this->loadFileConfig();
        $preview = $file['dip_viewer']['browse_preview'] ?? [];
        $value = $preview['collage_min_images'] ?? self::DEFAULT_BROWSE_PREVIEW_COLLAGE_MIN;

        return max(1, (int) $value);
    }

    public function getBrowsePreviewCollageMaxImages(): int
    {
        $fromSetting = $this->getModuleSetting('browse_preview_collage_max_images');
        if ($fromSetting !== null && $fromSetting !== '') {
            return max(1, (int) $fromSetting);
        }
        $file = $this->loadFileConfig();
        $preview = $file['dip_viewer']['browse_preview'] ?? [];
        $value = $preview['collage_max_images'] ?? self::DEFAULT_BROWSE_PREVIEW_COLLAGE_MAX;

        return max(1, (int) $value);
    }

    public function getPreviewCacheDirectory(): string
    {
        $fromSetting = $this->getModuleSetting('preview_cache_directory');
        if ($fromSetting) {
            return (string) $fromSetting;
        }
        $file = $this->loadFileConfig();
        $preview = $file['dip_viewer']['browse_preview'] ?? [];

        return $this->resolvePathUnderOmekaRoot($preview['cache_directory'] ?? 'data/dip_preview_cache');
    }

    protected function resolvePathUnderOmekaRoot(string $configured): string
    {
        if ($configured !== '' && $configured[0] === '/') {
            return $configured;
        }
        $root = defined('OMEKA_PATH') ? OMEKA_PATH : dirname(__DIR__, 4);

        return rtrim($root, '/') . '/' . ltrim($configured, '/');
    }

    public function getConfigFilePath(): string
    {
        return '/config/settings.yaml';
    }

    /**
     * @return mixed
     */
    protected function getModuleSetting(string $suffix)
    {
        return $this->settings->get('omeka_dip_viewer.' . $suffix);
    }

    protected function loadFileConfig(): array
    {
        if ($this->fileConfig !== null) {
            return $this->fileConfig;
        }
        $path = $this->getConfigFilePath();
        if (!is_readable($path)) {
            $this->fileConfig = [];
            return $this->fileConfig;
        }
        if (function_exists('yaml_parse_file')) {
            $parsed = @yaml_parse_file($path);
            $this->fileConfig = is_array($parsed) ? $parsed : [];
            return $this->fileConfig;
        }
        $raw = file_get_contents($path);
        $this->fileConfig = ['dip_viewer' => []];
        if (preg_match('/large_package_threshold_bytes:\s*(\d+)/', $raw, $m)) {
            $this->fileConfig['dip_viewer']['large_package_threshold_bytes'] = (int) $m[1];
        }
        if (preg_match('/block_large_packages:\s*(true|false)/i', $raw, $m)) {
            $this->fileConfig['dip_viewer']['block_large_packages'] = strtolower($m[1]) === 'true';
        }
        return $this->fileConfig;
    }
}
