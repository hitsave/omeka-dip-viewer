#!/usr/bin/env php
<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Service/MetsParser.php';

use OmekaDipViewer\Service\MetsParser;

if ($argc < 2) {
    fwrite(STDERR, "Usage: parse_fixture.php <bag_dir>\n");
    exit(1);
}

$bagDir = rtrim($argv[1], '/');
$inventoryPath = $bagDir . '/metadata/inventory.json';
if (!is_readable($inventoryPath)) {
    fwrite(STDERR, "Missing {$inventoryPath}\n");
    exit(1);
}

$inventory = json_decode(file_get_contents($inventoryPath), true);
if (!is_array($inventory)) {
    fwrite(STDERR, "Invalid inventory JSON\n");
    exit(1);
}

$parser = new MetsParser();
$index = $parser->indexBagItFromInventory($inventory, null, null);
echo json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
echo 'files: ' . count($index['files']) . "\n";
