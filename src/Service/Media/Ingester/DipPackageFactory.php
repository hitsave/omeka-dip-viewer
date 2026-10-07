<?php
namespace OmekaDipViewer\Service\Media\Ingester;

use OmekaDipViewer\Media\Ingester\DipPackage;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class DipPackageFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new DipPackage(
            $services->get('Omeka\File\TempFileFactory'),
            $services->get('OmekaDipViewer\Service\MetsParser'),
            $services->get('OmekaDipViewer\Service\DipConfig')
        );
    }
}
