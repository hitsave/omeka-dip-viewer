<?php
namespace OmekaDipViewer\Service\ViewHelper;

use OmekaDipViewer\Service\DipBrowsePreviewService;
use OmekaDipViewer\ViewHelper\DipBrowseThumbnail;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class DipBrowseThumbnailFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, ?array $options = null)
    {
        return new DipBrowseThumbnail($services->get(DipBrowsePreviewService::class));
    }
}
