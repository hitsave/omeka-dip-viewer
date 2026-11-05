<?php
namespace OmekaDipViewer\Service;

use Omeka\Api\Representation\MediaRepresentation;

/**
 * Catalog policy: preservation dumps are preview-only on the public site.
 */
class DipCatalogPolicy
{
    public function isPreservationDump(MediaRepresentation $media): bool
    {
        $data = $media->mediaData();
        if (strtolower(trim((string) ($data['ingest_kind'] ?? ''))) === 'dump') {
            return true;
        }
        foreach (['dip_wasabi_uri', 'package_wasabi_uri'] as $uriKey) {
            $uri = (string) ($data[$uriKey] ?? '');
            if (preg_match('#^s3://[^/]+/dumps/#', $uri)) {
                return true;
            }
        }
        $prefix = ltrim(str_replace('\\', '/', (string) ($data['package_storage_prefix'] ?? '')), '/');

        return str_starts_with($prefix, 'dumps/');
    }

    public function allowsPublicPayloadDownload(MediaRepresentation $media): bool
    {
        return !$this->isPreservationDump($media);
    }

    /**
     * Inner S3 path for a manifest preview key, never bag payload:
     * thumb = 640 px grid JPEG; display / original = web-size display JPEG (archiver #102), falling
     * back to the grid JPEG for packages built before display JPEGs existed.
     *
     * @param array<string, mixed>|null $manifest
     */
    public function resolveManifestPreviewInnerPath(?array $manifest, string $fileKey, string $variant): ?string
    {
        if (!is_array($manifest)) {
            return null;
        }
        $fileKey = $this->cleanKey($fileKey);
        if ($fileKey === '') {
            return null;
        }
        $grid = $this->previewPath($manifest['grid'] ?? null, $fileKey);
        if ($grid === null) {
            return null;
        }
        if ($variant === 'thumb') {
            return $grid;
        }
        if ($variant === 'display' || $variant === 'original') {
            return $this->previewPath($manifest['display'] ?? null, $fileKey) ?? $grid;
        }

        return null;
    }

    /**
     * Scan images the manifest lists (files.<key>.scan): the only payload a dump exposes publicly.
     *
     * @param array<string, mixed>|null $manifest
     * @return list<array{key: string, path: string, label: string, folder: string, size: int}>
     */
    public function scanFiles(?array $manifest): array
    {
        $files = is_array($manifest) ? ($manifest['files'] ?? null) : null;
        if (!is_array($files)) {
            return [];
        }
        $out = [];
        foreach ($files as $key => $info) {
            $key = $this->cleanKey((string) $key);
            if ($key === '' || !is_array($info) || empty($info['scan'])) {
                continue;
            }
            $path = $this->payloadPath((string) ($info['path'] ?? ''));
            if ($path === null) {
                continue;
            }
            $parts = explode('/', $path);
            $out[] = [
                'key' => $key,
                'path' => $path,
                'label' => (string) array_pop($parts),
                'folder' => (string) end($parts),
                'size' => (int) ($info['size'] ?? 0),
            ];
        }
        usort($out, fn (array $a, array $b): int => strnatcasecmp($a['path'], $b['path']));

        return $out;
    }

    /**
     * Bag payload path of a listed scan (variant "scan" download), or null for anything else.
     *
     * @param array<string, mixed>|null $manifest
     */
    public function resolveScanPayloadInnerPath(?array $manifest, string $fileKey): ?string
    {
        $fileKey = $this->cleanKey($fileKey);
        foreach ($this->scanFiles($manifest) as $scan) {
            if ($scan['key'] === $fileKey) {
                return $scan['path'];
            }
        }

        return null;
    }

    /**
     * Human label for a gallery key: payload file name from the manifest, else the key.
     *
     * @param array<string, mixed>|null $manifest
     */
    public function galleryLabel(?array $manifest, string $fileKey): string
    {
        $files = is_array($manifest) ? ($manifest['files'] ?? null) : null;
        $info = is_array($files) ? ($files[$fileKey] ?? null) : null;
        $path = is_array($info) ? $this->payloadPath((string) ($info['path'] ?? '')) : null;
        if ($path === null) {
            return $fileKey;
        }
        $parts = explode('/', $path);

        return (string) end($parts);
    }

    private function cleanKey(string $fileKey): string
    {
        return preg_replace('/[^a-z0-9_-]/i', '', $fileKey) ?: '';
    }

    private function previewPath(mixed $table, string $fileKey): ?string
    {
        if (!is_array($table) || !isset($table[$fileKey])) {
            return null;
        }
        $path = trim(str_replace('\\', '/', (string) $table[$fileKey]), '/');
        if ($path === '' || !str_starts_with($path, 'metadata/hitsave/') || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }

    private function payloadPath(string $path): ?string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if (!str_starts_with($path, 'data/') || in_array('..', explode('/', $path), true)) {
            return null;
        }

        return $path;
    }

    public function isBagPayloadInnerPath(string $innerPath): bool
    {
        $path = ltrim(str_replace('\\', '/', $innerPath), '/');

        return str_starts_with($path, 'data/');
    }

    /**
     * @param array<string, mixed>|null $manifest
     * @return list<string>
     */
    public function packagedPreviewFileKeys(?array $manifest): array
    {
        if (!is_array($manifest)) {
            return [];
        }
        $fromManifest = $manifest['gallery_file_keys'] ?? null;
        if (is_array($fromManifest) && $fromManifest !== []) {
            $keys = [];
            foreach ($fromManifest as $key) {
                if (is_string($key) && $key !== '') {
                    $keys[] = $key;
                }
            }
            if ($keys !== []) {
                return $keys;
            }
        }
        $grid = $manifest['grid'] ?? [];
        if (!is_array($grid)) {
            return [];
        }

        return array_keys($grid);
    }
}
