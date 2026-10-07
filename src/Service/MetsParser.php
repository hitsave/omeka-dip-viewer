<?php
namespace OmekaDipViewer\Service;

use DOMDocument;
use DOMXPath;

/**
 * Parse DIP METS (Archivematica-style or E-ARK CSIP) and index files inside a .tar (read-only).
 */
class MetsParser
{
    /** @var list<string>|null reused for the duration of indexTar() */
    protected ?array $tarMemberListing = null;

    /**
     * @return array{mets_path: string, package_uuid: ?string, files: array<int, array>, tree: array}
     */
    public function indexTar(string $tarPath): array
    {
        if (!is_readable($tarPath)) {
            throw new \InvalidArgumentException('DIP archive is not readable.');
        }
        $this->tarMemberListing = $this->listTarMembers($tarPath);
        try {
            $metsInnerPath = $this->findMetsPathInTar($tarPath);
            if (!$metsInnerPath) {
                throw new \RuntimeException('No METS file found in DIP archive.');
            }
            $metsXml = $this->readTarMember($tarPath, $metsInnerPath);
            $dom = new DOMDocument;
            if (!$dom->loadXML($metsXml)) {
                throw new \RuntimeException('METS XML is invalid.');
            }
            $this->validateMets($dom);

            $packageUuid = $this->packageUuidFromMets($dom, $metsInnerPath);
            $metsDir = dirname(str_replace('\\', '/', $metsInnerPath));
            if ($metsDir === '.') {
                $metsDir = '';
            }

            $files = $this->buildFileIndex($dom, $tarPath, $metsDir);
            $tree = $this->buildTree($files);

            return [
                'mets_inner_path' => $metsInnerPath,
                'package_uuid' => $packageUuid,
                'files' => $files,
                'tree' => $tree,
            ];
        } finally {
            $this->tarMemberListing = null;
        }
    }

    public function readTarMember(string $tarPath, string $innerPath): string
    {
        $innerPath = ltrim(str_replace('\\', '/', $innerPath), '/');
        $member = $this->resolveTarMemberPath($tarPath, $innerPath);
        $escapedTar = escapeshellarg($tarPath);
        $escapedMember = escapeshellarg($member);
        $contents = shell_exec("tar -xOf $escapedTar $escapedMember 2>/dev/null");
        if ($contents === null || $contents === false) {
            throw new \RuntimeException('File not found in DIP: ' . $innerPath);
        }
        return $contents;
    }

    public function extractTarMemberToFile(string $tarPath, string $innerPath, string $destPath): void
    {
        $innerPath = ltrim(str_replace('\\', '/', $innerPath), '/');
        $prevListing = $this->tarMemberListing;
        $this->tarMemberListing = $this->listTarMembers($tarPath);
        try {
            $member = $this->resolveTarMemberPath($tarPath, $innerPath);
            $dir = dirname($destPath);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create stream cache directory.');
            }
            $escapedTar = escapeshellarg($tarPath);
            $escapedMember = escapeshellarg($member);
            $escapedDest = escapeshellarg($destPath);
            $code = 1;
            $execOutput = [];
            exec("tar -xOf $escapedTar $escapedMember > $escapedDest 2>/dev/null", $execOutput, $code);
            if ($code !== 0 || !is_readable($destPath)) {
                @unlink($destPath);
                throw new \RuntimeException('File not found in DIP: ' . $innerPath);
            }
        } finally {
            $this->tarMemberListing = $prevListing;
        }
    }

    protected function resolveTarMemberPath(string $tarPath, string $innerPath): string
    {
        foreach ($this->getTarMemberListing($tarPath) as $member) {
            $normalized = ltrim(str_replace('\\', '/', $member), './');
            if ($normalized === $innerPath || str_ends_with($normalized, '/' . $innerPath)) {
                return $member;
            }
        }
        throw new \RuntimeException('File not found in DIP: ' . $innerPath);
    }

    protected function findMetsPathInTar(string $tarPath): ?string
    {
        $candidates = [];
        foreach ($this->getTarMemberListing($tarPath) as $member) {
            $member = ltrim(str_replace('\\', '/', $member), './');
            if (preg_match('#(^|/)METS\.xml$#i', $member)) {
                $candidates[] = [$member, substr_count($member, '/')];
            }
        }
        if ($candidates === []) {
            return null;
        }
        usort($candidates, fn (array $a, array $b): int => $a[1] <=> $b[1]);

        return $candidates[0][0];
    }

    protected function packageUuidFromMets(DOMDocument $dom, string $metsInnerPath): ?string
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('mets', 'http://www.loc.gov/METS/');
        $root = $xpath->query('/mets:mets')->item(0);
        if ($root instanceof \DOMElement) {
            $objid = trim($root->getAttribute('OBJID'));
            if ($objid !== '') {
                return $objid;
            }
        }
        if (preg_match('/METS\.([0-9a-f-]{36})\.xml$/i', basename($metsInnerPath), $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    /**
     * @return list<string>
     */
    protected function getTarMemberListing(string $tarPath): array
    {
        if ($this->tarMemberListing !== null) {
            return $this->tarMemberListing;
        }

        return $this->listTarMembers($tarPath);
    }

    /**
     * @return list<string>
     */
    protected function listTarMembers(string $tarPath): array
    {
        $escaped = escapeshellarg($tarPath);
        $output = shell_exec("tar -tf $escaped 2>/dev/null");
        if (!$output) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode("\n", $output))));
    }

    protected function sizeFromMetsFileNode(\DOMElement $fileNode, string $tarPath, string $innerPath): int
    {
        $sizeAttr = trim($fileNode->getAttribute('SIZE'));
        if ($sizeAttr !== '' && ctype_digit($sizeAttr)) {
            return (int) $sizeAttr;
        }

        return $this->tarMemberSize($tarPath, $innerPath);
    }

    protected function validateMets(DOMDocument $dom): void
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('mets', 'http://www.loc.gov/METS/');
        if (!$xpath->query('/mets:mets')->item(0)) {
            throw new \RuntimeException('Not a valid METS document.');
        }
        $hasEarkData = (bool) $xpath->query('//mets:fileGrp[@USE="Data"]')->item(0);
        $hasEarkRep = (bool) $xpath->query('//mets:fileGrp[starts-with(@USE, "Representations/")]')->item(0);
        if (!$hasEarkData && !$hasEarkRep) {
            throw new \RuntimeException('E-ARK DIP METS must reference Representations or Data file groups.');
        }
        foreach ($xpath->query('//mets:FLocat') as $flocat) {
            $href = $flocat->getAttributeNS('http://www.w3.org/1999/xlink', 'href')
                ?: $flocat->getAttribute('xlink:href');
            if (str_contains($href, '..')) {
                throw new \RuntimeException('Invalid METS href.');
            }
        }
    }

    /**
     * @return array<string, array{inner_path: string, size: int, mime: ?string, label: string}>
     */
    protected function buildAccessIndexByGroup(DOMXPath $xpath, string $tarPath, string $metsDir): array
    {
        $byGroup = [];
        foreach ($xpath->query('//mets:fileGrp[@USE="access"]//mets:file') as $fileNode) {
            if (!$fileNode instanceof \DOMElement) {
                continue;
            }
            $groupId = trim($fileNode->getAttribute('GROUPID'));
            if ($groupId === '') {
                continue;
            }
            $flocat = $xpath->query('mets:FLocat', $fileNode)->item(0);
            if (!$flocat) {
                continue;
            }
            $href = $flocat->getAttributeNS('http://www.w3.org/1999/xlink', 'href')
                ?: $flocat->getAttribute('xlink:href');
            $href = str_replace('\\', '/', $href);
            $innerPath = $metsDir === '' ? $href : $metsDir . '/' . $href;
            $innerPath = preg_replace('#/+#', '/', $innerPath);
            $label = trim($fileNode->getAttribute('LABEL'));
            if ($label === '') {
                $label = basename($href);
            }
            $mime = trim($fileNode->getAttribute('MIMETYPE')) ?: null;
            $size = $this->sizeFromMetsFileNode($fileNode, $tarPath, $innerPath);
            $byGroup[$groupId] = [
                'inner_path' => $innerPath,
                'size' => $size,
                'mime' => $mime ?: $this->guessMime($label),
                'label' => $label,
            ];
        }

        return $byGroup;
    }

    /**
     * @return list<array{key: string, file_id: string, label: string, relative_path: string, inner_path: string, size: int, mime: ?string, group_id: ?string, access_inner_path: ?string, access_size: ?int, access_mime: ?string}>
     */
    protected function buildFileIndex(DOMDocument $dom, string $tarPath, string $metsDir): array
    {
        return $this->buildEarkFileIndex($dom, $tarPath, $metsDir);
    }

    /**
     * @return list<array>
     */
    protected function buildEarkFileIndex(DOMDocument $dom, string $tarPath, string $metsDir): array
    {
        $repInner = $this->resolveRepresentationMetsInnerPath($dom, $tarPath, $metsDir);
        if ($repInner === null) {
            throw new \RuntimeException('E-ARK DIP: no representation METS found.');
        }
        $repXml = $this->readTarMember($tarPath, $repInner);
        $repDom = new DOMDocument;
        if (!$repDom->loadXML($repXml)) {
            throw new \RuntimeException('Representation METS XML is invalid.');
        }
        $repDir = dirname(str_replace('\\', '/', $repInner));
        if ($repDir === '.') {
            $repDir = '';
        }
        $xpath = new DOMXPath($repDom);
        $xpath->registerNamespace('mets', 'http://www.loc.gov/METS/');
        $accessByGroup = $this->buildAccessIndexByGroup($xpath, $tarPath, $repDir);
        $files = [];
        $position = 0;
        foreach ($xpath->query('//mets:fileGrp[@USE="Data"]//mets:file') as $fileNode) {
            if (!$fileNode instanceof \DOMElement) {
                continue;
            }
            $fileId = $fileNode->getAttribute('ID') ?: 'file-' . $position;
            $flocat = $xpath->query('mets:FLocat', $fileNode)->item(0);
            if (!$flocat) {
                continue;
            }
            $href = $flocat->getAttributeNS('http://www.w3.org/1999/xlink', 'href')
                ?: $flocat->getAttribute('xlink:href');
            $href = str_replace('\\', '/', $href);
            $innerPath = $repDir === '' ? $href : $repDir . '/' . $href;
            $innerPath = preg_replace('#/+#', '/', $innerPath);
            $relativePath = trim(str_replace('\\', '/', $fileNode->getAttribute('LABEL')));
            if ($relativePath === '') {
                $relativePath = $this->relativePathFromEarkDataHref($href);
            }
            $label = basename($relativePath);
            $key = 'f' . $position;
            $size = $this->sizeFromMetsFileNode($fileNode, $tarPath, $innerPath);
            $groupId = trim($fileNode->getAttribute('GROUPID')) ?: null;
            $access = ($groupId !== null && isset($accessByGroup[$groupId]))
                ? $accessByGroup[$groupId]
                : null;
            $mime = trim($fileNode->getAttribute('MIMETYPE')) ?: $this->guessMime($label);
            $files[] = [
                'key' => $key,
                'file_id' => $fileId,
                'label' => $label,
                'relative_path' => $relativePath,
                'inner_path' => $innerPath,
                'size' => $size,
                'mime' => $mime,
                'group_id' => $groupId,
                'access_inner_path' => $access['inner_path'] ?? null,
                'access_size' => $access['size'] ?? null,
                'access_mime' => $access['mime'] ?? null,
            ];
            ++$position;
        }
        if ($files === []) {
            throw new \RuntimeException('E-ARK DIP: no Data files in representation METS.');
        }

        return $files;
    }

    protected function relativePathFromEarkDataHref(string $href): string
    {
        $href = ltrim(str_replace('\\', '/', $href), '/');
        if (str_starts_with($href, 'data/')) {
            return substr($href, 5);
        }

        return $href;
    }

    protected function resolveRepresentationMetsInnerPath(DOMDocument $dom, string $tarPath, string $metsDir): ?string
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('mets', 'http://www.loc.gov/METS/');
        $xpath->registerNamespace('xlink', 'http://www.w3.org/1999/xlink');
        foreach ($xpath->query('//mets:fileGrp[starts-with(@USE, "Representations/")]//mets:FLocat') as $flocat) {
            $href = $flocat->getAttributeNS('http://www.w3.org/1999/xlink', 'href')
                ?: $flocat->getAttribute('xlink:href');
            if ($href === '') {
                continue;
            }
            $href = str_replace('\\', '/', $href);
            $inner = $metsDir === '' ? $href : $metsDir . '/' . $href;

            return preg_replace('#/+#', '/', $inner);
        }
        foreach ($xpath->query('//mets:mptr') as $mptr) {
            $href = $mptr->getAttributeNS('http://www.w3.org/1999/xlink', 'href')
                ?: $mptr->getAttribute('xlink:href');
            if ($href === '') {
                continue;
            }
            $href = str_replace('\\', '/', $href);
            $inner = $metsDir === '' ? $href : $metsDir . '/' . $href;

            return preg_replace('#/+#', '/', $inner);
        }

        return null;
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

    protected function tarMemberSize(string $tarPath, string $innerPath): int
    {
        try {
            return strlen($this->readTarMember($tarPath, $innerPath));
        } catch (\Throwable $e) {
            return 0;
        }
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
