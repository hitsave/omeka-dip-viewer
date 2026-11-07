<?php
declare(strict_types=1);

use OmekaDipViewer\Service\BagItInventoryIndexParser;

require dirname(__DIR__) . '/vendor/autoload.php';

// Disc contents bags (archiver build_disc_contents_bag.py): web copies and programs.
$parser = new BagItInventoryIndexParser();
$index = $parser->indexBagItFromInventory([
    'files' => [
        ['path' => 'data/2002DPK/movies/robocop.mpg', 'size_bytes' => 12, 'kind' => 'video',
         'access' => ['path' => 'metadata/hitsave/access/f2.mp4', 'mime' => 'video/mp4', 'size_bytes' => 3]],
        ['path' => 'data/2002DPK/press/us_rlh_fact.doc', 'size_bytes' => 5, 'kind' => 'document',
         'access' => ['path' => 'metadata/hitsave/access/f3.pdf', 'mime' => 'application/pdf', 'size_bytes' => 4]],
        ['path' => 'data/2002DPK/2002DPK.exe', 'size_bytes' => 2, 'kind' => 'program', 'download' => false],
        ['path' => 'data/2002DPK/sneaky.jpg', 'size_bytes' => 2, 'access' => ['path' => 'data/2002DPK/2002DPK.exe']],
        ['path' => 'data/2002DPK/up.jpg', 'size_bytes' => 2, 'access' => ['path' => 'metadata/hitsave/access/../../bag-info.txt']],
    ],
], null, null);
$byPath = [];
foreach ($index['files'] as $f) {
    $byPath[$f['relative_path']] = $f;
}
$mpg = $byPath['2002DPK/movies/robocop.mpg'];
assert($mpg['access_inner_path'] === 'metadata/hitsave/access/f2.mp4' && $mpg['access_mime'] === 'video/mp4');
assert($mpg['access_size'] === 3 && $mpg['access_label'] === 'robocop.mp4' && $mpg['download'] === true);
assert($byPath['2002DPK/press/us_rlh_fact.doc']['access_label'] === 'us_rlh_fact.pdf');
assert($byPath['2002DPK/2002DPK.exe']['download'] === false && $byPath['2002DPK/2002DPK.exe']['kind'] === 'program');
assert($byPath['2002DPK/sneaky.jpg']['access_inner_path'] === null);   // web copies only from metadata/hitsave/access/
assert($byPath['2002DPK/up.jpg']['access_inner_path'] === null);       // no traversal

// Press material inventories have neither: unchanged behaviour.
$press = $parser->indexBagItFromInventory(['files' => [['path' => 'data/kit/a.png', 'size_bytes' => 1]]], null, null);
assert($press['files'][0]['download'] === true && $press['files'][0]['access_inner_path'] === null);

echo "BagItInventoryIndexParserTest OK\n";
