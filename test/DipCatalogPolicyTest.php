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

// Display JPEGs + scan listing (archiver #102 manifests).
$scanManifest = [
    'grid' => ['f6' => 'metadata/hitsave/thumbnails/grid/f6.jpg', 'f7' => 'metadata/hitsave/thumbnails/grid/f7.jpg'],
    'display' => ['f6' => 'metadata/hitsave/display/f6.jpg'],
    'files' => [
        'f7' => ['path' => 'data/game/Scans/0002.png', 'size' => 91750722, 'scan' => true],
        'f6' => ['path' => 'data/game/Scans/0001.png', 'size' => 125076631, 'scan' => true],
        'f2' => ['path' => 'data/game/cover.jpg', 'size' => 10, 'scan' => false],
        'f3' => ['path' => 'data/../secrets.png', 'size' => 1, 'scan' => true],
        'f4' => ['path' => 'metadata/x.png', 'size' => 1, 'scan' => true],
    ],
    'gallery_file_keys' => ['f6', 'f7'],
];
assert($policy->resolveManifestPreviewInnerPath($scanManifest, 'f6', 'display') === 'metadata/hitsave/display/f6.jpg');
assert($policy->resolveManifestPreviewInnerPath($scanManifest, 'f6', 'original') === 'metadata/hitsave/display/f6.jpg');
assert($policy->resolveManifestPreviewInnerPath($scanManifest, 'f6', 'thumb') === 'metadata/hitsave/thumbnails/grid/f6.jpg');
assert($policy->resolveManifestPreviewInnerPath($scanManifest, 'f7', 'display') === 'metadata/hitsave/thumbnails/grid/f7.jpg'); // no display: grid
assert($policy->resolveManifestPreviewInnerPath($manifest, 'menu', 'display') === 'metadata/hitsave/thumbnails/grid/menu.jpg'); // old manifest
$scans = $policy->scanFiles($scanManifest);
assert(array_column($scans, 'key') === ['f6', 'f7']);            // only safe data/ scans, natural order
assert($scans[0]['label'] === '0001.png' && $scans[0]['folder'] === 'Scans' && $scans[0]['size'] === 125076631);
assert($policy->resolveScanPayloadInnerPath($scanManifest, 'f6') === 'data/game/Scans/0001.png');
assert($policy->resolveScanPayloadInnerPath($scanManifest, 'f2') === null);   // not a scan
assert($policy->resolveScanPayloadInnerPath($scanManifest, 'f3') === null);   // traversal
assert($policy->resolveScanPayloadInnerPath($scanManifest, 'f4') === null);   // not payload
assert($policy->resolveScanPayloadInnerPath($manifest, 'menu') === null);     // old manifest: no scans
assert($policy->scanFiles(null) === []);
assert($policy->galleryLabel($scanManifest, 'f7') === '0002.png');
assert($policy->galleryLabel($manifest, 'menu') === 'menu');

echo "DipCatalogPolicyTest OK\n";
