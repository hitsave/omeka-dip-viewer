<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Service/BagItInventoryIndexParser.php';

use OmekaDipViewer\Service\BagItInventoryIndexParser;

$inventoryPath = $argv[1] ?? (__DIR__ . '/fixtures/inventory.json');
$raw = file_get_contents($inventoryPath);
if ($raw === false) {
    fwrite(STDERR, "Cannot read {$inventoryPath}\n");
    exit(1);
}
$inventory = json_decode($raw, true);
if (!is_array($inventory)) {
    fwrite(STDERR, "Invalid JSON in {$inventoryPath}\n");
    exit(1);
}

$preview = null;
$previewPath = $argv[2] ?? null;
if ($previewPath !== null && is_readable($previewPath)) {
    $previewRaw = file_get_contents($previewPath);
    $preview = is_string($previewRaw) ? json_decode($previewRaw, true) : null;
}

$parser = new BagItInventoryIndexParser();
$index = $parser->indexBagItFromInventory($inventory, is_array($preview) ? $preview : null, 'test-uuid');
echo json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
