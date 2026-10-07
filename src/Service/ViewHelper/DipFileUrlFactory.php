<?php
namespace OmekaDipViewer\Service\ViewHelper;

use OmekaDipViewer\ViewHelper\DipFileUrl;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class DipFileUrlFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $name, ?array $options = null)
    {
        return new DipFileUrl(
            $services->get('Omeka\Settings'),
            $services->get('Omeka\ModuleManager')
        );
    }
}
