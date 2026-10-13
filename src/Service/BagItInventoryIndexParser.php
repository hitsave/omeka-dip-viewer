<?php
namespace OmekaDipViewer\Service;

/**
 * Index browseable files inside an RFC 8493 BagIt package (metadata/inventory.json).
 */
class BagItInventoryIndexParser
{
    /**
     * @param array<string, mixed> $inventory
     * @param array<string, mixed>|null $previewManifest
     * @return array{bagit_anchor_relpath: string, package_uuid: ?string, package_format: string, files: array, tree: array, preview_manifest: ?array}
     */
    public function indexBagItFromInventory(array $inventory, ?array $previewManifest, ?string $packageUuid): array
    {
        $files = [];
        $entries = $inventory['files'] ?? [];
        if (!is_array($entries)) {
            $entries = [];
        }
        foreach ($entries as $rec) {
            if (!is_array($rec)) {
                continue;
            }
            $normalized = ltrim(str_replace('\\', '/', (string) ($rec['path'] ?? '')), '/');
            if (!str_starts_with($normalized, 'data/') || str_ends_with($normalized, '/')) {
                continue;
            }
            $relativePath = substr($normalized, strlen('data/'));
            if ($relativePath === '' || $relativePath === false) {
                continue;
            }
            $label = basename($relativePath);
            $size = (int) ($rec['size_bytes'] ?? 0);
            $mime = isset($rec['mime']) ? (trim((string) $rec['mime']) ?: null) : null;
            $mime = $mime ?: $this->guessMime($label);
            $files[] = [
                'key' => '',
                'file_id' => '',
                'label' => $label,
                'relative_path' => $relativePath,
                'inner_path' => $normalized,
                'size' => $size,
                'mime' => $mime,
                'group_id' => null,
                'access_inner_path' => null,
                'access_size' => null,
                'access_mime' => null,
            ];
        }
        if ($files === []) {
            throw new \RuntimeException('BagIt package: no payload files under data/.');
        }
        usort($files, fn (array $a, array $b): int => strcmp($a['relative_path'], $b['relative_path']));
        foreach ($files as $position => &$file) {
            $file['key'] = 'f' . $position;
            $file['file_id'] = 'bagit-' . $position;
        }
        unset($file);

        $tree = $this->buildTree($files);

        return [
            'bagit_anchor_relpath' => 'bagit.txt',
            'package_uuid' => $packageUuid,
            'package_format' => 'bagit',
            'files' => $files,
            'tree' => $tree,
            'preview_manifest' => $previewManifest,
        ];
    }

    /**
     * @param list<array> $files
     */
    protected function buildTree(array $files): array
    {
        $root = ['name' => '/', 'children' => [], 'files' => []];
        foreach ($files as $file) {
            $relative = str_replace('\\', '/', $file['relative_path'] ?? $file['label']);
            $dir = dirname($relative);
            $parts = $dir === '.' ? [] : explode('/', $dir);
            $parts = array_values(array_filter($parts, fn ($p) => $p !== '' && $p !== '.'));
            $node = &$root;
            foreach ($parts as $part) {
                if (!isset($node['children'][$part])) {
                    $node['children'][$part] = ['name' => $part, 'children' => [], 'files' => []];
                }
                $node = &$node['children'][$part];
            }
            $node['files'][] = $file['key'];
        }

        return $root;
    }

    protected function guessMime(string $filename): ?string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'txt' => 'text/plain',
            default => null,
        };
    }
}
