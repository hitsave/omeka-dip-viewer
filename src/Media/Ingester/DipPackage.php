<?php
namespace OmekaDipViewer\Media\Ingester;

use OmekaDipViewer\Media\DipPackageMedia;
use OmekaDipViewer\Service\DipConfig;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;
use Omeka\File\TempFileFactory;

class DipPackage implements IngesterInterface
{
    protected TempFileFactory $tempFileFactory;
    protected DipConfig $dipConfig;

    public function __construct(TempFileFactory $tempFileFactory, DipConfig $dipConfig)
    {
        $this->tempFileFactory = $tempFileFactory;
        $this->dipConfig = $dipConfig;
    }

    public function getLabel()
    {
        return 'DIP package (browse in place)'; // @translate
    }

    public function getRenderer()
    {
        return DipPackageMedia::RENDERER;
    }

    public function form(\Laminas\View\Renderer\PhpRenderer $view, array $options = [])
    {
        return '
        <div class="field">
            <div class="field-meta">
                <label>' . $view->translate('BagIt packages are attached via API (Wasabi S3 prefix).') . '</label>
            </div>
        </div>';
    }

    public function ingest(Media $media, Request $request, ErrorStore $errorStore): void
    {
        if ($this->ingestFromExternalBagStorage($media, $request, $errorStore)) {
            return;
        }

        $errorStore->addError(
            'upload',
            'Set data.package_storage_prefix and data.dip_storage=wasabi-s3 (no file upload).'
        );
    }

    /**
     * Register DIP media from unpacked BagIt prefix on Wasabi (S3 API reads in OmekaDipViewer).
     *
     * @return bool true when external ingest completed or failed (do not fall through to upload)
     */
    protected function ingestFromExternalBagStorage(Media $media, Request $request, ErrorStore $errorStore): bool
    {
        $data = $this->mergeIngestData($media, $request);
        $prefix = trim((string) ($data['package_storage_prefix'] ?? ''));
        $storage = trim((string) ($data['dip_storage'] ?? ''));
        if ($prefix === '') {
            if ($storage === 'wasabi-s3') {
                $errorStore->addError('upload', 'package_storage_prefix is required for wasabi-s3 DIP media.');
                return true;
            }
            return false;
        }

        $payload = [
            'package_storage_prefix' => $prefix,
            'dip_storage' => $storage !== '' ? $storage : 'wasabi-s3',
            'dip_size_bytes' => (int) ($data['dip_size_bytes'] ?? 0),
            'large_package' => false,
            'large_package_threshold_bytes' => $this->dipConfig->getLargePackageThresholdBytes(),
            'original_filename' => basename(rtrim($prefix, '/')),
            'packaged_previews' => true,
        ];
        foreach (['dip_wasabi_uri', 'package_wasabi_uri', 'dip_sha256', 'package_uuid'] as $key) {
            if (isset($data[$key]) && (string) $data[$key] !== '') {
                $payload[$key] = (string) $data[$key];
            }
        }

        $media->setIngester(DipPackageMedia::RENDERER);
        $media->setRenderer($this->getRenderer());
        $media->setData($payload);
        $media->setSource(basename(rtrim($prefix, '/')));

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function mergeIngestData(Media $media, Request $request): array
    {
        $data = $request->getContent();
        if (!is_array($data)) {
            $data = [];
        }
        if (isset($data['data']) && is_array($data['data'])) {
            $data = array_merge($data, $data['data']);
        }
        $mediaData = $media->getData();
        if (is_array($mediaData) && $mediaData !== []) {
            $data = array_merge($data, $mediaData);
        }

        return $data;
    }
}
