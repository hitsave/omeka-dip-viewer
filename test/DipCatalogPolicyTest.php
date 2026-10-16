<?php
declare(strict_types=1);

use OmekaDipViewer\Service\DipCatalogPolicy;

require dirname(__DIR__) . '/vendor/autoload.php';

$policy = new DipCatalogPolicy();

$manifest = [
    'grid' => ['menu' => 'metadata/hitsave/thumbnails/grid/menu.jpg'],
    'gallery_file_keys' => ['menu'],
];

assert($policy->resolveManifestPreviewInnerPath($manifest, 'menu', 'thumb') === 'metadata/hitsave/thumbnails/grid/menu.jpg');
assert($policy->resolveManifestPreviewInnerPath($manifest, 'menu', 'original') === 'metadata/hitsave/thumbnails/grid/menu.jpg');
assert($policy->resolveManifestPreviewInnerPath($manifest, 'f1', 'original') === null);
assert($policy->packagedPreviewFileKeys($manifest) === ['menu']);
assert($policy->isBagPayloadInnerPath('data/disc.iso') === true);
assert($policy->isBagPayloadInnerPath('metadata/hitsave/browse-medium.png') === false);

echo "DipCatalogPolicyTest OK\n";
