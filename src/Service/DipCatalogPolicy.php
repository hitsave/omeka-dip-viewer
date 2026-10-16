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
     * Inner S3 path for a manifest preview key (grid thumb or full JPEG), not bag payload.
     *
     * @param array<string, mixed>|null $manifest
     */
    public function resolveManifestPreviewInnerPath(?array $manifest, string $fileKey, string $variant): ?string
    {
        if (!is_array($manifest)) {
            return null;
        }
        $fileKey = preg_replace('/[^a-z0-9_-]/i', '', $fileKey) ?: '';
        if ($fileKey === '') {
            return null;
        }
        $grid = $manifest['grid'] ?? [];
        if (!is_array($grid) || !isset($grid[$fileKey])) {
            return null;
        }
        $path = trim(str_replace('\\', '/', (string) $grid[$fileKey]), '/');
        if ($path === '' || !str_starts_with($path, 'metadata/hitsave/')) {
            return null;
        }
        if ($variant === 'thumb' || $variant === 'original') {
            return $path;
        }

        return null;
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
