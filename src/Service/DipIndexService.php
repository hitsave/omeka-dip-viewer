<?php
namespace OmekaDipViewer\Service;

use OmekaDipViewer\Media\DipPackageMedia;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\File\Store\StoreInterface;

/**
 * Build DIP file indexes from the stored .tar at read time (with optional file cache).
 */
class DipIndexService
{
    protected MetsParser $metsParser;
    protected StoreInterface $store;
    protected DipConfig $dipConfig;

    /** @var array<int, array|null> in-request memoization */
    protected array $requestCache = [];

    public function __construct(MetsParser $metsParser, StoreInterface $store, DipConfig $dipConfig)
    {
        $this->metsParser = $metsParser;
        $this->store = $store;
        $this->dipConfig = $dipConfig;
    }

    /**
     * @return array{mets_inner_path: string, package_uuid: ?string, files: array, tree: array}|null
     */
    public function getIndex(MediaRepresentation $media): ?array
    {
        $mediaId = (int) $media->id();
        if (array_key_exists($mediaId, $this->requestCache)) {
            return $this->requestCache[$mediaId];
        }

        $tarPath = $this->resolveTarPath($media);
        if ($tarPath === null) {
            $legacy = $this->legacyStoredIndex($media);
            $this->requestCache[$mediaId] = $legacy;

            return $legacy;
        }

        $cacheFile = $this->cacheFilePath($mediaId, $tarPath);
        if ($cacheFile !== null) {
            $cached = $this->readCacheFile($cacheFile);
            if ($cached !== null) {
                $this->requestCache[$mediaId] = $cached;

                return $cached;
            }
        }

        try {
            $index = $this->metsParser->indexTar($tarPath);
        } catch (\Throwable $e) {
            $legacy = $this->legacyStoredIndex($media);
            $this->requestCache[$mediaId] = $legacy;

            return $legacy;
        }

        if ($cacheFile !== null) {
            $this->writeCacheFile($cacheFile, $index);
        }

        $this->requestCache[$mediaId] = $index;

        return $index;
    }

    /**
     * @return array{key: string, file_id: string, label: string, relative_path: string, inner_path: string, size: int, mime: ?string}|null
     */
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

    public function resolveTarPath(MediaRepresentation $media): ?string
    {
        if (!DipPackageMedia::isDipPackage($media->renderer())) {
            return null;
        }
        if (!$media->hasOriginal() || !$media->filename()) {
            return null;
        }
        $storagePath = sprintf('original/%s', $media->filename());
        $tarPath = $this->store->getLocalPath($storagePath);
        if (!$tarPath || !is_readable($tarPath)) {
            return null;
        }

        return $tarPath;
    }

    /**
     * @return array|null legacy ingest snapshot when tar is unavailable
     */
    protected function legacyStoredIndex(MediaRepresentation $media): ?array
    {
        $data = $media->mediaData();
        $index = $data['dip_index'] ?? null;

        return is_array($index) ? $index : null;
    }

    protected function cacheFilePath(int $mediaId, string $tarPath): ?string
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
        $mtime = (string) filemtime($tarPath);
        $size = (string) filesize($tarPath);
        $token = hash('sha256', $tarPath . '|' . $mtime . '|' . $size);

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
     * @param array{mets_inner_path: string, package_uuid: ?string, files: array, tree: array} $index
     */
    protected function writeCacheFile(string $path, array $index): void
    {
        $json = json_encode($index, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        file_put_contents($path, $json, LOCK_EX);
    }
}
