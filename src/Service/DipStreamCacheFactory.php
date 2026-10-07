<?php
namespace OmekaDipViewer\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class DipStreamCacheFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new DipStreamCache(
            $services->get(DipConfig::class),
            $services->get(MetsParser::class)
        );
    }
}
