<?php
require_once __DIR__ . '/../src/Service/MetsParser.php';

use OmekaDipViewer\Service\MetsParser;

$tar = $argv[1] ?? '/tmp/sample-game.tar';
$parser = new MetsParser();
$index = $parser->indexTar($tar);
echo json_encode($index, JSON_PRETTY_PRINT) . "\n";
if (getenv('DIP_VIEWER_PARSE_JSON_ONLY')) {
    exit(0);
}
$first = $index['files'][0] ?? null;
if ($first) {
    $blob = $parser->readTarMember($tar, $first['inner_path']);
    echo "Read {$first['label']}: " . strlen($blob) . " bytes\n";
}
