<?php
declare(strict_types=1);

use OmekaDipViewer\Service\DipBrowsePreviewService;

require dirname(__DIR__) . '/vendor/autoload.php';

// The item page lists a dump's own package before its disc contents (dip-gallery.js reads this).
assert(DipBrowsePreviewService::packageKind(['package_storage_prefix' => 'disc-contents/Redump/IBM PC compatible/Multimedia/N/x']) === 'disc-contents');
assert(DipBrowsePreviewService::packageKind(['package_storage_prefix' => 'dumps/Redump/IBM PC compatible/Multimedia/N/x']) === 'package');
assert(DipBrowsePreviewService::packageKind(['package_storage_prefix' => 'press-material/A/x']) === 'package');
assert(DipBrowsePreviewService::packageKind([]) === 'package');

echo "DipPackageKindTest OK\n";
