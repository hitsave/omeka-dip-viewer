<?php
namespace OmekaDipViewer\Service;

/**
 * Select DIP originals that have an access copy suitable for in-browser playback.
 */
class DipVideoFilter
{
    /** @var list<string> */
    public const DEFAULT_MIME_TYPES = [
        'video/mp4',
        'video/webm',
    ];

    /**
     * @param array<int, array<string, mixed>> $files
     * @return list<array<string, mixed>>
     */
    public function filterPlaybackFiles(array $files): array
    {
        $out = [];
        foreach ($files as $file) {
            if (!$this->isPlaybackEligible($file)) {
                continue;
            }
            $out[] = $file;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $file
     */
    public function isPlaybackEligible(array $file): bool
    {
        if (empty($file['access_inner_path'])) {
            return false;
        }

        $mime = strtolower(trim((string) ($file['access_mime'] ?? $file['mime'] ?? '')));
        if ($mime !== '' && in_array($mime, self::DEFAULT_MIME_TYPES, true)) {
            return true;
        }

        $label = (string) ($file['label'] ?? '');
        $ext = strtolower(pathinfo($label, PATHINFO_EXTENSION));

        return in_array($ext, ['mp4', 'webm'], true);
    }
}
