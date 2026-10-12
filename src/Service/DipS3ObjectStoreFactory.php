<?php
namespace OmekaDipViewer\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class DipS3ObjectStoreFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new DipS3ObjectStore($services->get(DipS3Config::class));
    }
}
