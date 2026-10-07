<?php
namespace OmekaDipViewer\Controller;

use OmekaDipViewer\Media\DipPackageMedia;
use OmekaDipViewer\Service\DipIndexService;
use OmekaDipViewer\Service\DipStreamCache;
use OmekaDipViewer\Service\MetsParser;
use Laminas\Mvc\Controller\AbstractActionController;

class DipFileController extends AbstractActionController
{
    /** Stream from disk cache when at or above this size (bytes). */
    protected const STREAM_SIZE_THRESHOLD = 1048576;

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
        $entry = $indexService->getFileEntry($media, $fileKey);
        if (!$entry) {
            return $this->getResponse()->setStatusCode(404);
        }

        $tarPath = $indexService->resolveTarPath($media);
        if (!$tarPath) {
            return $this->getResponse()->setStatusCode(500);
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

        $useStream = $variant === 'access'
            || $sizeHint >= self::STREAM_SIZE_THRESHOLD
            || str_starts_with(strtolower($mime), 'video/');

        if ($useStream) {
            /** @var DipStreamCache $streamCache */
            $streamCache = $services->get(DipStreamCache::class);
            try {
                $localPath = $streamCache->localPathForMember($tarPath, $innerPath, $mediaId);

                return $this->sendRangedFileResponse($localPath, $mime, $label);
            } catch (\Throwable $e) {
                return $this->getResponse()->setStatusCode(404);
            }
        }

        /** @var MetsParser $parser */
        $parser = $services->get(MetsParser::class);
        try {
            $contents = $parser->readTarMember($tarPath, $innerPath);
        } catch (\Throwable $e) {
            return $this->getResponse()->setStatusCode(404);
        }

        $response = $this->getResponse();
        $response->getHeaders()
            ->addHeaderLine('Content-Type', $mime)
            ->addHeaderLine('Content-Length', (string) strlen($contents))
            ->addHeaderLine('Content-Disposition', 'inline; filename="' . addslashes($label) . '"');
        $response->setContent($contents);

        return $response;
    }

    protected function sendRangedFileResponse(string $path, string $mime, string $downloadName)
    {
        if (!is_readable($path)) {
            return $this->getResponse()->setStatusCode(404);
        }

        $size = filesize($path);
        if ($size === false) {
            return $this->getResponse()->setStatusCode(500);
        }

        $response = $this->getResponse();
        $response->getHeaders()
            ->addHeaderLine('Content-Type', $mime)
            ->addHeaderLine('Accept-Ranges', 'bytes')
            ->addHeaderLine('Content-Disposition', 'inline; filename="' . addslashes($downloadName) . '"');

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
            $length = $end - $start + 1;
            $response->setStatusCode(206);
            $response->getHeaders()
                ->addHeaderLine('Content-Range', "bytes $start-$end/$size")
                ->addHeaderLine('Content-Length', (string) $length);

            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return $this->getResponse()->setStatusCode(500);
            }
            fseek($handle, $start);
            $chunk = stream_get_contents($handle, $length);
            fclose($handle);
            $response->setContent($chunk !== false ? $chunk : '');

            return $response;
        }

        $response->getHeaders()->addHeaderLine('Content-Length', (string) $size);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return $this->getResponse()->setStatusCode(500);
        }
        $contents = stream_get_contents($handle);
        fclose($handle);
        $response->setContent($contents !== false ? $contents : '');

        return $response;
    }
}
