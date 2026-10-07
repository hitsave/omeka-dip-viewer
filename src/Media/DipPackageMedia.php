<?php
namespace OmekaDipViewer\Media;

final class DipPackageMedia
{
    public const RENDERER = 'omeka_dip_package';

    public static function isDipPackage(?string $renderer): bool
    {
        return $renderer === self::RENDERER;
    }
}
