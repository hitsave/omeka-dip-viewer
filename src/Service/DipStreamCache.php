<?php
namespace OmekaDipViewer\Service;

/**
 * Extract tar members to disk so HTTP Range requests can stream large access copies.
 */
class DipStreamCache
{
    protected DipConfig $dipConfig;
    protected MetsParser $metsParser;

    public function __construct(DipConfig $dipConfig, MetsParser $metsParser)
    {
        $this->dipConfig = $dipConfig;
        $this->metsParser = $metsParser;
    }

    public function localPathForMember(string $tarPath, string $innerPath, int $mediaId): string
    {
        $dir = $this->dipConfig->getStreamCacheDirectory() . '/' . $mediaId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create DIP stream cache directory.');
        }

        $hash = hash('sha256', $innerPath);
        $ext = pathinfo($innerPath, PATHINFO_EXTENSION);
        $dest = $dir . '/' . $hash . ($ext !== '' ? '.' . $ext : '');

        $tarMtime = @filemtime($tarPath) ?: 0;
        if (is_readable($dest) && (@filemtime($dest) ?: 0) >= $tarMtime) {
            return $dest;
        }

        $partial = $dest . '.partial';
        if (is_file($partial)) {
            @unlink($partial);
        }
        $this->metsParser->extractTarMemberToFile($tarPath, $innerPath, $partial);
        if (!rename($partial, $dest)) {
            @unlink($partial);
            throw new \RuntimeException('Failed to finalize stream cache file.');
        }

        return $dest;
    }
}
