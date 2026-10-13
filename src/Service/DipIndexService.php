<?php
namespace OmekaDipViewer\Service;

use OmekaDipViewer\Media\DipPackageMedia;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\File\Store\StoreInterface;

/**
 * Build DIP file indexes from Wasabi S3 BagIt prefixes (with optional file cache).
 */
class DipIndexService
{
    protected BagItInventoryIndexParser $bagItInventoryIndexParser;
    protected StoreInterface $store;
    protected DipConfig $dipConfig;
    protected DipPackageLocator $locator;
    protected DipS3ObjectStore $s3;

    /** @var array<int, array|null> in-request memoization */
    protected array $requestCache = [];

    /** @var array<int, array<string, mixed>|null|false> */
    protected array $manifestRequestCache = [];

    public function __construct(
        BagItInventoryIndexParser $bagItInventoryIndexParser,
        StoreInterface $store,
        DipConfig $dipConfig,
        DipPackageLocator $locator,
        DipS3ObjectStore $s3
    ) {
        $this->bagItInventoryIndexParser = $bagItInventoryIndexParser;
        $this->store = $store;
        $this->dipConfig = $dipConfig;
        $this->locator = $locator;
        $this->s3 = $s3;
    }

    /**
     * @return array{bagit_anchor_relpath: string, package_uuid: ?string, files: array, tree: array}|null
     */
    public function getIndex(MediaRepresentation $media): ?array
    {
        $mediaId = (int) $media->id();
        if (array_key_exists($mediaId, $this->requestCache)) {
            return $this->requestCache[$mediaId];
        }

        $located = $this->locator->locate($media);
        if ($located === null || $located['type'] !== 's3') {
            $this->requestCache[$mediaId] = null;

            return null;
        }
        $index = $this->buildIndexFromS3Prefix($mediaId, (string) $located['prefix']);
        $this->requestCache[$mediaId] = $index;

        return $index;
    }

    /**
     * @return array{key: string, file_id: string, label: string, relative_path: string, inner_path: string, size: int, mime: ?string}|null
     */
    /**
     * @return array<string, mixed>|null
     */
    public function getPreviewManifest(MediaRepresentation $media): ?array
    {
        $mediaId = (int) $media->id();
        if (array_key_exists($mediaId, $this->manifestRequestCache)) {
            $cached = $this->manifestRequestCache[$mediaId];

            return is_array($cached) ? $cached : null;
        }

        if (array_key_exists($mediaId, $this->requestCache)) {
            $index = $this->requestCache[$mediaId];
            if (is_array($index) && isset($index['preview_manifest']) && is_array($index['preview_manifest'])) {
                $this->manifestRequestCache[$mediaId] = $index['preview_manifest'];

                return $index['preview_manifest'];
            }
        }

        $located = $this->locator->locate($media);
        if ($located === null) {
            $this->manifestRequestCache[$mediaId] = false;

            return null;
        }
        if ($located['type'] === 's3') {
            $prefix = (string) $located['prefix'];
            $manifest = $this->readPreviewManifestFromS3($mediaId, $prefix);
            $this->manifestRequestCache[$mediaId] = is_array($manifest) ? $manifest : false;

            return is_array($manifest) ? $manifest : null;
        }

        $this->manifestRequestCache[$mediaId] = false;

        return null;
    }

    public function getPackagedMemberPath(MediaRepresentation $media, string $innerPath): ?string
    {
        $manifest = $this->getPreviewManifest($media);
        if (!$manifest) {
            return null;
        }
        $innerPath = ltrim(str_replace('\\', '/', $innerPath), '/');
        foreach (['browse', 'grid'] as $section) {
            if (!isset($manifest[$section]) || !is_array($manifest[$section])) {
                continue;
            }
            foreach ($manifest[$section] as $path) {
                if (is_string($path) && ltrim($path, '/') === $innerPath) {
                    return ltrim($path, '/');
                }
            }
        }

        return null;
    }

    public function getFileEntry(MediaRepresentation $media, string $fileKey): ?array
    {
        $index = $this->getIndex($media);
        if (!$index) {
            return null;
        }
        foreach ($index['files'] as $file) {
            if (($file['key'] ?? '') === $fileKey) {
                return $file;
            }
        }

        return null;
    }

    public function resolveS3Prefix(MediaRepresentation $media): ?string
    {
        $located = $this->locator->locate($media);
        if ($located === null || $located['type'] !== 's3') {
            return null;
        }

        return (string) $located['prefix'];
    }

    public function packageIsAvailable(MediaRepresentation $media): bool
    {
        return $this->locator->locate($media) !== null;
    }

    /**
     * @return array{bagit_anchor_relpath: string, package_uuid: ?string, files: array, tree: array}|null
     */
    protected function buildIndexFromS3Prefix(int $mediaId, string $prefix): ?array
    {
        $identity = 's3:' . $prefix;
        $cacheFile = $this->cacheFilePathForIdentity($mediaId, $identity);
        $apcuKey = $this->apcuCacheKey($mediaId, $identity);
        if ($cacheFile !== null) {
            $cached = $this->readCacheFile($cacheFile);
            if ($cached !== null) {
                $this->storeApcuIndex($mediaId, $apcuKey, $cached);

                return $cached;
            }
        }
        if ($apcuKey !== null) {
            $fromApcu = $this->fetchApcuIndex($apcuKey);
            if ($fromApcu !== null) {
                if ($cacheFile !== null) {
                    $this->writeCacheFile($cacheFile, $fromApcu);
                }

                return $fromApcu;
            }
        }

        try {
            $inventoryRaw = $this->s3->getObject($prefix, 'metadata/inventory.json');
            $inventory = json_decode($inventoryRaw, true);
            if (!is_array($inventory)) {
                throw new \RuntimeException('Invalid inventory.json on S3.');
            }
            $preview = $this->readPreviewManifestFromS3($mediaId, $prefix);
            $packageUuid = null;
            try {
                $subRaw = $this->s3->getObject($prefix, 'metadata/submission.json');
                $sub = json_decode($subRaw, true);
                if (is_array($sub) && !empty($sub['package_uuid'])) {
                    $packageUuid = (string) $sub['package_uuid'];
                }
            } catch (\Throwable $e) {
                // optional
            }
            $index = $this->bagItInventoryIndexParser->indexBagItFromInventory($inventory, $preview, $packageUuid);
        } catch (\Throwable $e) {
            return null;
        }

        if ($cacheFile !== null) {
            $this->writeCacheFile($cacheFile, $index);
        }
        $this->storeApcuIndex($mediaId, $apcuKey, $index);

        return $index;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function readPreviewManifestFromS3(int $mediaId, string $prefix): ?array
    {
        $identity = 's3:' . $prefix;
        $manifestCacheFile = $this->manifestCacheFilePathForIdentity($mediaId, $identity);
        if ($manifestCacheFile !== null) {
            $cached = $this->readManifestCacheFile($manifestCacheFile);
            if ($cached !== null) {
                return $cached;
            }
        }
        try {
            $raw = $this->s3->getObject($prefix, 'metadata/hitsave/preview-manifest.json');
        } catch (\Throwable $e) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['manifest_version'])) {
            return null;
        }
        if ($manifestCacheFile !== null) {
            $this->writeManifestCacheFile($manifestCacheFile, $decoded);
        }

        return $decoded;
    }

    protected function manifestCacheFilePathForIdentity(int $mediaId, string $identity): ?string
    {
        $indexPath = $this->cacheFilePathForIdentity($mediaId, $identity);
        if ($indexPath === null) {
            return null;
        }

        return preg_replace('/\.json$/', '-manifest.json', $indexPath) ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function readManifestCacheFile(string $path): ?array
    {
        if (!is_readable($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['manifest_version'])) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    protected function writeManifestCacheFile(string $path, array $manifest): void
    {
        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        file_put_contents($path, $json, LOCK_EX);
    }

    protected function cacheFilePathForIdentity(int $mediaId, string $identity): ?string
    {
        if (!$this->dipConfig->isIndexCacheEnabled()) {
            return null;
        }
        $dir = $this->dipConfig->getIndexCacheDirectory();
        if ($dir === '' || (!is_dir($dir) && !@mkdir($dir, 0755, true))) {
            return null;
        }
        if (!is_writable($dir)) {
            return null;
        }
        $token = hash('sha256', $identity);

        return $dir . '/media-' . $mediaId . '-' . substr($token, 0, 16) . '.json';
    }

    /**
     * @return array|null
     */
    protected function readCacheFile(string $path): ?array
    {
        if (!is_readable($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['files'])) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array{bagit_anchor_relpath: string, package_uuid: ?string, files: array, tree: array} $index
     */
    protected function writeCacheFile(string $path, array $index): void
    {
        $json = json_encode($index, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        file_put_contents($path, $json, LOCK_EX);
    }

    /**
     * Drop in-request, APCu, and on-disk BagIt indexes for a media row (e.g. after media update).
     */
    public function invalidateIndexCache(int $mediaId): void
    {
        unset($this->requestCache[$mediaId], $this->manifestRequestCache[$mediaId]);

        if (!$this->dipConfig->isIndexCacheEnabled()) {
            return;
        }

        $dir = $this->dipConfig->getIndexCacheDirectory();
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        $pattern = $dir . '/media-' . $mediaId . '-*';
        foreach (glob($pattern) ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        if (!function_exists('apcu_delete') || !function_exists('apcu_fetch')) {
            return;
        }

        $pointerKey = $this->apcuPointerKey($mediaId);
        $success = false;
        $indexKey = apcu_fetch($pointerKey, $success);
        if ($success && is_string($indexKey) && $indexKey !== '') {
            apcu_delete($indexKey);
        }
        apcu_delete($pointerKey);
    }

    protected function apcuPointerKey(int $mediaId): string
    {
        return 'omeka_dip_idx_media_' . $mediaId;
    }

    protected function apcuCacheKey(int $mediaId, string $identity): ?string
    {
        if (!$this->dipConfig->isIndexCacheEnabled() || $this->dipConfig->getIndexCacheApcuTtlSeconds() <= 0) {
            return null;
        }
        if (!function_exists('apcu_store') || !function_exists('apcu_fetch')) {
            return null;
        }
        $token = hash('sha256', $identity);

        return 'omeka_dip_idx_' . $mediaId . '_' . substr($token, 0, 16);
    }

    /**
     * @return array|null
     */
    protected function fetchApcuIndex(?string $key): ?array
    {
        if ($key === null) {
            return null;
        }
        $success = false;
        $raw = apcu_fetch($key, $success);
        if (!$success || !is_array($raw) || !isset($raw['files'])) {
            return null;
        }

        return $raw;
    }

    /**
     * @param array{bagit_anchor_relpath: string, package_uuid: ?string, files: array, tree: array} $index
     */
    protected function storeApcuIndex(int $mediaId, ?string $key, array $index): void
    {
        if ($key === null) {
            return;
        }
        $ttl = $this->dipConfig->getIndexCacheApcuTtlSeconds();
        if ($ttl <= 0) {
            return;
        }
        apcu_store($key, $index, $ttl);
        apcu_store($this->apcuPointerKey($mediaId), $key, $ttl);
    }
}
