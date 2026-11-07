<?php
declare(strict_types=1);

use OmekaDipViewer\Service\DipBrowsePreviewService;

require dirname(__DIR__) . '/vendor/autoload.php';

// Browse thumbnails of data-disc dumps come from their disc contents package.
assert(DipBrowsePreviewService::isDiscContentsPrefix('disc-contents/Redump/IBM PC compatible/Multimedia/T/x') === true);
assert(DipBrowsePreviewService::isDiscContentsPrefix('/disc-contents/Redump/x') === true);
assert(DipBrowsePreviewService::isDiscContentsPrefix('dumps/Redump/IBM PC compatible/Multimedia/T/x') === false);
assert(DipBrowsePreviewService::isDiscContentsPrefix('press-material/A/x') === false);
assert(DipBrowsePreviewService::isDiscContentsPrefix('') === false);

echo "DipBrowsePreviewTest OK\n";
