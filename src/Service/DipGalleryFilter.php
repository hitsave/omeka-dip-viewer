<?php
namespace OmekaDipViewer\Service;

/**
 * Select DIP index files suitable for in-browser gallery preview.
 */
class DipGalleryFilter
{
    /** @var list<string> */
    public const DEFAULT_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /** @var list<string> */
    public const DEFAULT_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
    ];

    public const DEFAULT_MAX_BYTES = 52428800; // 50 MiB

    /**
     * @param array<int, array<string, mixed>> $files
     * @param list<string> $allowedMimeTypes
     * @return list<array<string, mixed>>
     */
    public function filterGalleryFiles(array $files, int $maxBytes, array $allowedMimeTypes): array
    {
        $out = [];
        foreach ($files as $file) {
            if (!$this->isGalleryEligible($file, $maxBytes, $allowedMimeTypes)) {
                continue;
            }
            $out[] = $file;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $file
     * @param list<string> $allowedMimeTypes
     */
    public function isGalleryEligible(array $file, int $maxBytes, array $allowedMimeTypes): bool
    {
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            return false;
        }

        $mime = strtolower(trim((string) ($file['mime'] ?? '')));
        if ($mime !== '' && in_array($mime, $allowedMimeTypes, true)) {
            return true;
        }

        $label = (string) ($file['label'] ?? $file['relative_path'] ?? '');
        $ext = strtolower(pathinfo($label, PATHINFO_EXTENSION));

        return $ext !== '' && in_array($ext, self::DEFAULT_EXTENSIONS, true);
    }
}
