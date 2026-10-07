<?php
namespace OmekaDipViewer\Controller;

use OmekaDipViewer\Media\DipPackageMedia;
use OmekaDipViewer\Service\DipBrowsePreviewService;
use Laminas\Mvc\Controller\AbstractActionController;

class DipBrowsePreviewController extends AbstractActionController
{
    public function previewAction()
    {
        $mediaId = (int) $this->params('media_id');
        $type = (string) $this->params()->fromQuery('type', 'medium');

        try {
            $media = $this->api()->read('media', $mediaId)->getContent();
        } catch (\Exception $e) {
            return $this->getResponse()->setStatusCode(404);
        }

        if (!$media->isPublic() && !$media->userIsAllowed('read')) {
            $item = $media->item();
            if (!$item || (!$item->isPublic() && !$item->userIsAllowed('read'))) {
                return $this->getResponse()->setStatusCode(404);
            }
        }

        if (!DipPackageMedia::isDipPackage($media->renderer())) {
            return $this->getResponse()->setStatusCode(404);
        }

        $services = $this->getEvent()->getApplication()->getServiceManager();
        /** @var DipBrowsePreviewService $previewService */
        $previewService = $services->get(DipBrowsePreviewService::class);

        try {
            [$path, $mime] = $previewService->resolvePreviewFile($media, $type);
        } catch (\Throwable $e) {
            return $this->getResponse()->setStatusCode(404);
        }

        if (!is_readable($path)) {
            return $this->getResponse()->setStatusCode(404);
        }

        $response = $this->getResponse();
        $response->getHeaders()
            ->addHeaderLine('Content-Type', $mime)
            ->addHeaderLine('Content-Length', (string) filesize($path))
            ->addHeaderLine('Cache-Control', 'public, max-age=3600, must-revalidate');
        $response->setContent(file_get_contents($path) ?: '');

        return $response;
    }
}
