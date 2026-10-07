<?php
namespace OmekaDipViewer\Media\Ingester;

use OmekaDipViewer\Media\DipPackageMedia;
use OmekaDipViewer\Service\DipConfig;
use OmekaDipViewer\Service\MetsParser;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;
use Omeka\File\TempFileFactory;

class DipPackage implements IngesterInterface
{
    protected TempFileFactory $tempFileFactory;
    protected MetsParser $metsParser;
    protected DipConfig $dipConfig;

    public function __construct(TempFileFactory $tempFileFactory, MetsParser $metsParser, DipConfig $dipConfig)
    {
        $this->tempFileFactory = $tempFileFactory;
        $this->metsParser = $metsParser;
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
        // Omeka echoes this string in media-field-wrapper.phtml (and in data-template attributes).
        return '
        <div class="field">
            <div class="field-meta">
                <label for="media-file-input-__index__">' . $view->translate('DIP TAR file') . '</label>
            </div>
            <div class="inputs">
                <input type="file" name="file[__index__]" id="media-file-input-__index__" class="media-file-input"
                    accept=".tar,.tar.gz,.tgz,application/x-tar" required>
                <input type="hidden" name="o:media[__index__][file_index]" value="__index__">
            </div>
        </div>';
    }

    public function ingest(Media $media, Request $request, ErrorStore $errorStore): void
    {
        $upload = $this->resolveUploadedFile($request);
        if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errorStore->addError('upload', 'A DIP .tar file is required.');
            return;
        }

        $name = $upload['name'] ?? 'dip.tar';
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['tar', 'gz', 'tgz'], true)) {
            $errorStore->addError('upload', 'File must be a DIP TAR archive.');
            return;
        }

        $sizeBytes = (int) ($upload['size'] ?? 0);
        $threshold = $this->dipConfig->getLargePackageThresholdBytes();
        $largePackage = $sizeBytes >= $threshold;
        if ($largePackage && $this->dipConfig->blockLargePackages()) {
            $errorStore->addError('upload', sprintf(
                'DIP is %d bytes (≥ %d). Large packages are blocked by configuration.',
                $sizeBytes,
                $threshold
            ));
            return;
        }

        $tempPath = $upload['tmp_name'];
        try {
            $index = $this->metsParser->indexTar($tempPath);
        } catch (\Throwable $e) {
            $errorStore->addError('upload', 'Invalid DIP: ' . $e->getMessage());
            return;
        }

        $tempFile = $this->tempFileFactory->build();
        $tempFile->setSourceName($name);
        if (!copy($tempPath, $tempFile->getTempPath())) {
            $errorStore->addError('upload', 'Could not stage DIP file.');
            return;
        }

        $media->setIngester(DipPackageMedia::RENDERER);
        $media->setRenderer($this->getRenderer());
        $media->setData([
            'dip_size_bytes' => $sizeBytes,
            'large_package' => $largePackage,
            'large_package_threshold_bytes' => $threshold,
            'original_filename' => $name,
            'package_uuid' => $index['package_uuid'] ?? null,
        ]);
        $media->setSource($name);

        $tempFile->mediaIngestFile($media, $request, $errorStore);
    }

    /**
     * @return array<string, mixed>|null PHP upload array (name, type, tmp_name, error, size)
     */
    protected function resolveUploadedFile(Request $request): ?array
    {
        $fileData = $request->getFileData();
        if (isset($fileData['file'])) {
            $file = $fileData['file'];
            if (isset($file['tmp_name'])) {
                return $file;
            }
            if (isset($file[0]) && is_array($file[0])) {
                return $file[0];
            }
        }

        $upload = $_FILES['file'] ?? null;
        return is_array($upload) ? $upload : null;
    }
}
