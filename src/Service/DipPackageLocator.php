<?php
namespace OmekaDipViewer\Service;

use OmekaDipViewer\Media\DipPackageMedia;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\File\Store\StoreInterface;

/**
 * Resolve DIP bytes from Wasabi S3 BagIt prefix.
 */
class DipPackageLocator
{
    protected StoreInterface $store;
    protected DipS3ObjectStore $s3;

    public function __construct(StoreInterface $store, DipS3ObjectStore $s3)
    {
        $this->store = $store;
        $this->s3 = $s3;
    }

    /**
     * @return array{type: string, prefix: string}|null
     */
    public function locate(MediaRepresentation $media): ?array
    {
        if (!DipPackageMedia::isDipPackage($media->renderer())) {
            return null;
        }
        $data = $media->mediaData();
        $prefix = trim((string) ($data['package_storage_prefix'] ?? ''));
        if ($prefix === '') {
            $prefix = $this->prefixFromWasabiUri((string) ($data['dip_wasabi_uri'] ?? ''));
        }
        if ($prefix === '') {
            $prefix = $this->prefixFromWasabiUri((string) ($data['package_wasabi_uri'] ?? ''));
        }
        if ($prefix !== '' && $this->s3->isEnabled()) {
            return ['type' => 's3', 'prefix' => $prefix];
        }

        return null;
    }

    protected function prefixFromWasabiUri(string $uri): string
    {
        if (!str_starts_with($uri, 's3://')) {
            return '';
        }
        $rest = substr($uri, 5);
        $slash = strpos($rest, '/');
        if ($slash === false) {
            return '';
        }
        $key = trim(substr($rest, $slash + 1), '/');
        if ($key === '') {
            return '';
        }
        if (str_ends_with(strtolower($key), '.tar')) {
            $key = substr($key, 0, -4);
        }

        return trim($key, '/');
    }
}
