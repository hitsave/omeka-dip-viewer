<?php
namespace OmekaDipViewer\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class DipPackageLocatorFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new DipPackageLocator(
            $services->get('Omeka\File\Store'),
            $services->get(DipS3ObjectStore::class),
            $services->get(DipS3Config::class)
        );
    }
}
