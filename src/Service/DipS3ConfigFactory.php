<?php
namespace OmekaDipViewer\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class DipS3ConfigFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $path = dirname(__DIR__, 2) . '/config/dip-s3.config.php';
        if (!is_readable($path)) {
            $path = dirname(__DIR__, 2) . '/config/dip-s3.config.php.dist';
        }
        $config = is_readable($path) ? include $path : [];

        return new DipS3Config(is_array($config) ? $config : []);
    }
}
