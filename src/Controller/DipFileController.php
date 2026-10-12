<?php
namespace OmekaDipViewer\Controller;

use OmekaDipViewer\Media\DipPackageMedia;
use OmekaDipViewer\Service\DipIndexService;
use OmekaDipViewer\Service\DipPreviewCache;
use OmekaDipViewer\Service\DipS3ObjectStore;
use Laminas\Mvc\Controller\AbstractActionController;

class DipFileController extends AbstractActionController
{
    public function streamAction()
    {
        $mediaId = (int) $this->params('media_id');
        $fileKey = (string) $this->params('file_key');
        $variant = (string) $this->params()->fromQuery('variant', 'original');

        return $this->streamDipFile($mediaId, $fileKey, $variant);
    }

    /**
     * ARK component path: ark:/NAAN/{item_id}/{dip_media_id}/{file_key}[.{variant}].
     */
    public function streamFromArkAction()
    {
        $naan = (string) $this->params('naan');
        $configuredNaan = (string) $this->settings()->get('ark_naan');
        if ($configuredNaan === '' || $naan !== $configuredNaan) {
            return $this->getResponse()->setStatusCode(404);
        }

        $itemId = (int) $this->params('name');
        $mediaId = (int) $this->params('media_id');
        $fileKeyParam = (string) $this->params('file_key');

        [$fileKey, $variant] = $this->parseFileKeyAndVariant($fileKeyParam);
        if ($fileKey === '') {
            return $this->getResponse()->setStatusCode(404);
        }

        try {
            $item = $this->api()->read('items', $itemId)->getContent();
        } catch (\Exception $e) {
            return $this->getResponse()->setStatusCode(404);
        }

        if (!$this->canReadItem($item)) {
            return $this->getResponse()->setStatusCode(404);
        }

        $mediaOnItem = false;
        foreach ($item->media() as $media) {
            if ((int) $media->id() === $mediaId) {
                $mediaOnItem = true;
                break;
            }
        }
        if (!$mediaOnItem) {
            return $this->getResponse()->setStatusCode(404);
        }

        return $this->streamDipFile($mediaId, $fileKey, $variant);
    }

    /**
     * @return array{0: string, 1: string} file key and variant (original|access|…)
     */
    protected function parseFileKeyAndVariant(string $fileKeyParam): array
    {
        if (preg_match('/^([A-Za-z0-9_]+)\.([a-z][a-z0-9_]*)$/', $fileKeyParam, $matches)) {
            return [$matches[1], $matches[2]];
        }

        return [$fileKeyParam, 'original'];
    }

    protected function canReadItem($item): bool
    {
        if ($item->isPublic()) {
            return true;
        }

        return (bool) $item->userIsAllowed('read');
    }

    protected function streamDipFile(int $mediaId, string $fileKey, string $variant)
    {
        try {
            $media = $this->api()->read('media', $mediaId)->getContent();
        } catch (\Exception $e) {
            return $this->getResponse()->setStatusCode(404);
        }

        if (!$media->isPublic() && !$media->userIsAllowed('read')) {
            $item = $media->item();
            if (!$item || !$this->canReadItem($item)) {
                return $this->getResponse()->setStatusCode(404);
            }
        }

        if (!DipPackageMedia::isDipPackage($media->renderer())) {
            return $this->getResponse()->setStatusCode(404);
        }

        $services = $this->getEvent()->getApplication()->getServiceManager();
        /** @var DipIndexService $indexService */
        $indexService = $services->get(DipIndexService::class);
        $s3Prefix = $indexService->resolveS3Prefix($media);
        if ($s3Prefix === null) {
            return $this->getResponse()->setStatusCode(500);
        }

        if ($variant === 'thumb') {
            return $this->streamPackagedThumbnail(
                $media,
                $mediaId,
                $fileKey,
                $s3Prefix,
                $indexService,
                $services
            );
        }

        $entry = $indexService->getFileEntry($media, $fileKey);
        if (!$entry) {
            return $this->getResponse()->setStatusCode(404);
        }

        $innerPath = $entry['inner_path'];
        $mime = $entry['mime'] ?? 'application/octet-stream';
        $label = $entry['label'];
        $sizeHint = (int) ($entry['size'] ?? 0);

        if ($variant === 'access') {
            if (empty($entry['access_inner_path'])) {
                return $this->getResponse()->setStatusCode(404);
            }
            $innerPath = $entry['access_inner_path'];
            $mime = $entry['access_mime'] ?? 'video/mp4';
            $sizeHint = (int) ($entry['access_size'] ?? 0);
            if (!empty($entry['access_label'])) {
                $label = $entry['access_label'];
            }
        } elseif ($variant !== 'original') {
            return $this->getResponse()->setStatusCode(404);
        }

        return $this->sendS3ObjectResponse($s3Prefix, $innerPath, $mime, $label, $services);
    }

    protected function sendS3ObjectResponse(
        string $prefix,
        string $innerPath,
        string $mime,
        string $label,
        $services
    ) {
        /** @var DipS3ObjectStore $s3 */
        $s3 = $services->get(DipS3ObjectStore::class);
        try {
            $size = $s3->headContentLength($prefix, $innerPath);
        } catch (\Throwable $e) {
            return $this->getResponse()->setStatusCode(404);
        }

        $response = $this->getResponse();
        $response->getHeaders()
            ->addHeaderLine('Content-Type', $mime)
            ->addHeaderLine('Accept-Ranges', 'bytes')
            ->addHeaderLine('Cache-Control', 'public, max-age=86400, immutable')
            ->addHeaderLine('Content-Disposition', 'inline; filename="' . addslashes($label) . '"');

        $rangeHeader = $this->getRequest()->getHeader('Range');
        $rangeValue = $rangeHeader ? $rangeHeader->getFieldValue() : '';
        if ($rangeValue !== '' && preg_match('/bytes=(\d+)-(\d*)/', $rangeValue, $matches)) {
            $start = (int) $matches[1];
            $end = $matches[2] === '' ? $size - 1 : min((int) $matches[2], $size - 1);
            if ($start > $end || $start >= $size) {
                $response->setStatusCode(416);
                $response->getHeaders()->addHeaderLine('Content-Range', 'bytes */' . $size);

                return $response;
            }
            try {
                $part = $s3->getObjectRange($prefix, $innerPath, $start, $end);
            } catch (\Throwable $e) {
                return $this->getResponse()->setStatusCode(404);
            }
            $length = $end - $start + 1;
            $response->setStatusCode(206);
            $response->getHeaders()
                ->addHeaderLine('Content-Range', "bytes $start-$end/$size")
                ->addHeaderLine('Content-Length', (string) $length);
            $response->setContent($part['body']);

            return $response;
        }

        try {
            $object = $s3->getObjectRange($prefix, $innerPath, null, null);
        } catch (\Throwable $e) {
            return $this->getResponse()->setStatusCode(404);
        }
        $response->getHeaders()->addHeaderLine('Content-Length', (string) $size);
        $response->setContent($object['body']);

        return $response;
    }

    protected function streamPackagedThumbnail(
        $media,
        int $mediaId,
        string $fileKey,
        string $s3Prefix,
        DipIndexService $indexService,
        $services
    ) {
        $manifest = $indexService->getPreviewManifest($media);
        $innerPath = is_array($manifest) ? ($manifest['grid'][$fileKey] ?? null) : null;
        if (!is_string($innerPath) || $innerPath === '') {
            return $this->getResponse()->setStatusCode(404);
        }

        /** @var DipPreviewCache $previewCache */
        $previewCache = $services->get(DipPreviewCache::class);
        $cachePath = $previewCache->packagedExtractPath($mediaId, $innerPath);
        if (!is_readable($cachePath)) {
            try {
                /** @var DipS3ObjectStore $s3 */
                $s3 = $services->get(DipS3ObjectStore::class);
                $bytes = $s3->getObject($s3Prefix, $innerPath);
                $dir = dirname($cachePath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($cachePath, $bytes);
            } catch (\Throwable $e) {
                return $this->getResponse()->setStatusCode(404);
            }
        }

        $response = $this->getResponse();
        $response->getHeaders()
            ->addHeaderLine('Content-Type', 'image/jpeg')
            ->addHeaderLine('Content-Length', (string) filesize($cachePath))
            ->addHeaderLine('Cache-Control', 'public, max-age=86400, immutable')
            ->addHeaderLine('Content-Disposition', 'inline; filename="' . addslashes(basename($innerPath)) . '"');
        $response->setContent(file_get_contents($cachePath) ?: '');

        return $response;
    }
}
