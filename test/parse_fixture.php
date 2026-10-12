<?php
require_once __DIR__ . '/../src/Service/MetsParser.php';

use OmekaDipViewer\Service\MetsParser;

$tar = $argv[1] ?? '/tmp/sample-game.tar';
$parser = new MetsParser();
$index = $parser->indexTar($tar);
$summary = [
    'package_format' => $index['package_format'] ?? 'e-ark',
    'mets_inner_path' => $index['mets_inner_path'] ?? null,
    'package_uuid' => $index['package_uuid'] ?? null,
    'file_count' => count($index['files'] ?? []),
    'files' => array_map(static fn (array $f): array => [
        'key' => $f['key'] ?? '',
        'relative_path' => $f['relative_path'] ?? '',
        'mime' => $f['mime'] ?? null,
    ], $index['files'] ?? []),
];
echo json_encode($summary, JSON_PRETTY_PRINT) . "\n";
if (getenv('DIP_VIEWER_PARSE_JSON_ONLY')) {
    exit(0);
}
$first = $index['files'][0] ?? null;
if ($first) {
    $blob = $parser->readTarMember($tar, $first['inner_path']);
    echo "Read {$first['label']}: " . strlen($blob) . " bytes\n";
}
