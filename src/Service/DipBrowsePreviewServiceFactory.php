<?php
namespace OmekaDipViewer\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class DipBrowsePreviewServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, ?array $options = null)
    {
        return new DipBrowsePreviewService(
            $services->get(DipIndexService::class),
            $services->get(DipConfig::class),
            $services->get(DipGalleryFilter::class),
            $services->get(DipPreviewCache::class),
            $services->get(DipCollageBuilder::class),
            $services->get(MetsParser::class)
        );
    }
}
