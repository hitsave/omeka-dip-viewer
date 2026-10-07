<?php
namespace OmekaDipViewer\Service;

/**
 * Two-row collage: scaled DIP images with slight tilt, spaced so tiles do not overlap.
 */
class DipCollageBuilder
{
    private const CARD_WIDTH_RATIO = 2.5;
    private const CARD_HEIGHT_RATIO = 3.5;
    private const TILE_GAP_PX = 6;

    /**
     * @param list<\GdImage> $images
     */
    public function build(array $images, int $canvasWidth, int $canvasHeight): \GdImage
    {
        if ($images === []) {
            throw new \InvalidArgumentException('Collage requires at least one image.');
        }

        $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);
        if ($canvas === false) {
            throw new \RuntimeException('Failed to allocate collage canvas.');
        }

        $this->fillTransparent($canvas);

        $count = count($images);
        $topCount = (int) ceil($count / 2);
        $rows = [
            array_slice($images, 0, $topCount),
            array_slice($images, $topCount),
        ];

        foreach ($rows as $rowIndex => $rowImages) {
            if ($rowImages === []) {
                continue;
            }
            $centerY = $rowIndex === 0
                ? (int) round($canvasHeight * 0.26)
                : (int) round($canvasHeight * 0.74);
            $this->layoutRow($canvas, $rowImages, $centerY, $canvasWidth);
        }

        return $canvas;
    }

    /**
     * @param list<\GdImage> $images
     */
    protected function layoutRow(\GdImage $canvas, array $images, int $centerY, int $canvasWidth): void
    {
        $maxUsableWidth = (int) round($canvasWidth * 0.99);
        $rowBand = (int) round(imagesy($canvas) * 0.5);
        $minAngle = -10.0;
        $maxAngle = 10.0;

        $lo = 36;
        $hi = max($lo, (int) round($rowBand * 0.98));
        $bestTiles = null;
        $bestTotalW = 0;

        while ($lo <= $hi) {
            $cardH = (int) floor(($lo + $hi) / 2);
            $cardW = (int) round($cardH * (self::CARD_WIDTH_RATIO / self::CARD_HEIGHT_RATIO));
            $tiles = $this->buildRotatedTiles($images, $cardW, $cardH, $minAngle, $maxAngle);
            if ($tiles === []) {
                break;
            }
            $totalW = $this->rowPixelWidth($tiles);
            if ($totalW <= $maxUsableWidth) {
                if ($bestTiles !== null) {
                    foreach ($bestTiles as $tile) {
                        imagedestroy($tile['img']);
                    }
                }
                $bestTiles = $tiles;
                $bestTotalW = $totalW;
                $lo = $cardH + 1;
            } else {
                foreach ($tiles as $tile) {
                    imagedestroy($tile['img']);
                }
                $hi = $cardH - 1;
            }
        }

        if ($bestTiles !== null) {
            $this->pasteRowTiles($canvas, $bestTiles, $centerY, $canvasWidth, $bestTotalW);
        }
    }

    /**
     * @param list<\GdImage> $images
     * @return list<array{img: \GdImage, w: int, h: int}>
     */
    protected function buildRotatedTiles(
        array $images,
        int $cardW,
        int $cardH,
        float $minAngle,
        float $maxAngle
    ): array {
        $n = count($images);
        $tiles = [];
        foreach ($images as $i => $photo) {
            $face = $this->scaleToFit($photo, $cardW, $cardH);
            $angle = $n > 1
                ? $minAngle + ($maxAngle - $minAngle) * ($i / ($n - 1))
                : 0.0;
            $bg = imagecolorallocatealpha($face, 0, 0, 0, 127);
            $rotated = imagerotate($face, $angle, $bg === false ? 0 : $bg);
            imagedestroy($face);
            if ($rotated === false) {
                continue;
            }
            imagesavealpha($rotated, true);
            imagealphablending($rotated, true);
            $tiles[] = [
                'img' => $rotated,
                'w' => imagesx($rotated),
                'h' => imagesy($rotated),
            ];
        }

        return $tiles;
    }

    /**
     * @param list<array{img: \GdImage, w: int, h: int}> $tiles
     */
    protected function rowPixelWidth(array $tiles): int
    {
        $n = count($tiles);
        if ($n === 0) {
            return 0;
        }
        $sum = 0;
        foreach ($tiles as $tile) {
            $sum += $tile['w'];
        }

        return $sum + self::TILE_GAP_PX * max(0, $n - 1);
    }

    /**
     * @param list<array{img: \GdImage, w: int, h: int}> $tiles
     */
    protected function pasteRowTiles(
        \GdImage $canvas,
        array $tiles,
        int $centerY,
        int $canvasWidth,
        int $totalW
    ): void {
        $x = (int) round(($canvasWidth - $totalW) / 2);
        foreach ($tiles as $tile) {
            $y = (int) round($centerY - $tile['h'] / 2);
            $this->pasteWithAlpha($canvas, $tile['img'], $x, $y);
            imagedestroy($tile['img']);
            $x += $tile['w'] + self::TILE_GAP_PX;
        }
    }

    public function scaleToFit(\GdImage $source, int $maxW, int $maxH): \GdImage
    {
        $sw = imagesx($source);
        $sh = imagesy($source);
        if ($sw <= 0 || $sh <= 0) {
            throw new \RuntimeException('Invalid source image dimensions.');
        }
        $scale = min($maxW / $sw, $maxH / $sh, 1.0);
        $dw = max(1, (int) round($sw * $scale));
        $dh = max(1, (int) round($sh * $scale));
        $dest = imagecreatetruecolor($dw, $dh);
        if ($dest === false) {
            throw new \RuntimeException('Failed to allocate scaled image.');
        }
        $this->fillTransparent($dest);
        imagealphablending($dest, true);
        imagecopyresampled($dest, $source, 0, 0, 0, 0, $dw, $dh, $sw, $sh);

        return $dest;
    }

    protected function fillTransparent(\GdImage $image): void
    {
        imagesavealpha($image, true);
        imagealphablending($image, false);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        if ($transparent === false) {
            throw new \RuntimeException('Failed to allocate transparent color.');
        }
        imagefill($image, 0, 0, $transparent);
        imagealphablending($image, true);
    }

    protected function pasteWithAlpha(\GdImage $canvas, \GdImage $overlay, int $x, int $y): void
    {
        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);
        imagecopy($canvas, $overlay, $x, $y, 0, 0, imagesx($overlay), imagesy($overlay));
    }
}
