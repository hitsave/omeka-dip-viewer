<?php
namespace OmekaDipViewer\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class DipIndexServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new DipIndexService(
            $services->get(MetsParser::class),
            $services->get('Omeka\File\Store'),
            $services->get(DipConfig::class)
        );
    }
}
