<?php
namespace OmekaDipViewer\Service;

use OmekaDipViewer\Media\DipPackageMedia;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\File\Store\StoreInterface;

/**
 * Resolve DIP BagIt prefix and Wasabi bucket from Omeka media metadata.
 */
class DipPackageLocator
{
    protected StoreInterface $store;
    protected DipS3ObjectStore $s3;
    protected DipS3Config $s3Config;

    public function __construct(StoreInterface $store, DipS3ObjectStore $s3, DipS3Config $s3Config)
    {
        $this->store = $store;
        $this->s3 = $s3;
        $this->s3Config = $s3Config;
    }

    /**
     * @return array{type: string, bucket: string, prefix: string}|null
     */
    public function locate(MediaRepresentation $media): ?array
    {
        if (!DipPackageMedia::isDipPackage($media->renderer())) {
            return null;
        }
        if (!$this->s3->isEnabled()) {
            return null;
        }

        $data = $media->mediaData();
        foreach (['dip_wasabi_uri', 'package_wasabi_uri'] as $uriKey) {
            $parsed = $this->parseS3Uri((string) ($data[$uriKey] ?? ''));
            if ($parsed !== null) {
                return [
                    'type' => 's3',
                    'bucket' => $parsed['bucket'],
                    'prefix' => $parsed['prefix'],
                ];
            }
        }

        $prefix = trim((string) ($data['package_storage_prefix'] ?? ''), '/');
        if ($prefix === '') {
            return null;
        }
        $bucket = $this->s3Config->getBucket();
        if ($bucket === '') {
            return null;
        }

        return ['type' => 's3', 'bucket' => $bucket, 'prefix' => $prefix];
    }

    /**
     * @return array{bucket: string, prefix: string}|null
     */
    protected function parseS3Uri(string $uri): ?array
    {
        if (!str_starts_with($uri, 's3://')) {
            return null;
        }
        $rest = substr($uri, 5);
        $slash = strpos($rest, '/');
        if ($slash === false) {
            return null;
        }
        $bucket = trim(substr($rest, 0, $slash));
        $prefix = trim(substr($rest, $slash + 1), '/');
        if ($bucket === '' || $prefix === '') {
            return null;
        }

        return ['bucket' => $bucket, 'prefix' => $prefix];
    }
}
