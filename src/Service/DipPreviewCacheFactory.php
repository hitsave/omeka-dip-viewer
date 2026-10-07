<?php
namespace OmekaDipViewer\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class DipPreviewCacheFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, ?array $options = null)
    {
        return new DipPreviewCache($services->get(DipConfig::class));
    }
}
