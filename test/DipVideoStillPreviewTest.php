<?php
declare(strict_types=1);

use OmekaDipViewer\Service\DipBrowsePreviewService;

require dirname(__DIR__) . '/vendor/autoload.php';

// Disc contents with a video and no images (FFVII Advent Children Trailer, media 31468).
$manifest = [
    'browse' => [],
    'grid' => ['f0' => 'metadata/hitsave/thumbnails/grid/f0.jpg'],
    'display' => ['f0' => 'metadata/hitsave/display/f0.jpg'],
    'video_file_keys' => ['f0'],
    'gallery_file_keys' => [],
];
// Cards get the poster frame; link previews (large) get the contact sheet.
assert(DipBrowsePreviewService::videoStillPath($manifest, 'medium') === 'metadata/hitsave/thumbnails/grid/f0.jpg');
assert(DipBrowsePreviewService::videoStillPath($manifest, 'square') === 'metadata/hitsave/thumbnails/grid/f0.jpg');
assert(DipBrowsePreviewService::videoStillPath($manifest, 'large') === 'metadata/hitsave/display/f0.jpg');

// Whichever still exists is used when the preferred one is missing.
assert(DipBrowsePreviewService::videoStillPath(['video_file_keys' => ['f0'], 'display' => ['f0' => 'd.jpg']], 'medium') === 'd.jpg');
assert(DipBrowsePreviewService::videoStillPath(['video_file_keys' => ['f0'], 'grid' => ['f0' => 'g.jpg']], 'large') === 'g.jpg');

// The first video with a still wins.
assert(DipBrowsePreviewService::videoStillPath(
    ['video_file_keys' => ['f1', 'f2'], 'grid' => ['f2' => 'g2.jpg']], 'medium') === 'g2.jpg');

// No videos, or no stills: nothing.
assert(DipBrowsePreviewService::videoStillPath(['video_file_keys' => [], 'grid' => ['f0' => 'g.jpg']], 'medium') === null);
assert(DipBrowsePreviewService::videoStillPath(['video_file_keys' => ['f0']], 'medium') === null);
assert(DipBrowsePreviewService::videoStillPath([], 'large') === null);

echo "DipVideoStillPreviewTest OK\n";
