<?php
namespace OmekaDipViewer\Service\Media\Renderer;

use OmekaDipViewer\Media\Renderer\DipPackage;
use OmekaDipViewer\Service\DipConfig;
use OmekaDipViewer\Service\DipGalleryFilter;
use OmekaDipViewer\Service\DipVideoFilter;
use OmekaDipViewer\Service\DipIndexService;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class DipPackageFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new DipPackage(
            $services->get(DipIndexService::class),
            $services->get(DipConfig::class),
            $services->get(DipGalleryFilter::class),
            $services->get(DipVideoFilter::class)
        );
    }
}
