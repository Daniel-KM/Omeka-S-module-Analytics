<?php declare(strict_types=1);

namespace Analytics\Stdlib;

/**
 * Convert the requested parts of a media into normalized buckets.
 *
 * The buckets allow to aggregate the most viewed zones of the images (grid of
 * 16 × 16 cells) and the most played parts of the audio and video files (20
 * slices of 5%), like a heat map.
 */
class SegmentBucketer
{
    /**
     * Number of columns and rows of the grid of an image.
     */
    const GRID = 16;

    /**
     * Number of slices of the duration of an audio or video file.
     */
    const SLICES = 20;

    /**
     * Get the cells of the grid covered by a region of an image, in pixels.
     *
     * The buckets are the indexes of the cells, row by row. The full image is
     * not a zone, so an empty array is returned for it, like for an invalid
     * region.
     *
     * @return int[]
     */
    public function regionCells(int $x, int $y, int $width, int $height, int $sourceWidth, int $sourceHeight): array
    {
        if ($sourceWidth <= 0 || $sourceHeight <= 0
            || $width <= 0 || $height <= 0
            || $x < 0 || $y < 0
            || $x >= $sourceWidth || $y >= $sourceHeight
            // The full image is a view, not a zone.
            || ($x === 0 && $y === 0 && $width >= $sourceWidth && $height >= $sourceHeight)
        ) {
            return [];
        }

        $grid = self::GRID;
        $colFirst = (int) floor($x * $grid / $sourceWidth);
        $colLast = (int) min($grid - 1, ceil(($x + min($width, $sourceWidth - $x)) * $grid / $sourceWidth) - 1);
        $rowFirst = (int) floor($y * $grid / $sourceHeight);
        $rowLast = (int) min($grid - 1, ceil(($y + min($height, $sourceHeight - $y)) * $grid / $sourceHeight) - 1);

        $cells = [];
        for ($row = $rowFirst; $row <= $rowLast; $row++) {
            for ($col = $colFirst; $col <= $colLast; $col++) {
                $cells[] = $row * $grid + $col;
            }
        }
        return $cells;
    }

    /**
     * Get the slice of a file for a byte offset, as a rough position in time.
     *
     * The offset in bytes is an approximation of the position in the media,
     * enough for a heat map of the most played parts.
     */
    public function timeSlice(int $offsetBytes, int $fileSize): ?int
    {
        if ($fileSize <= 0 || $offsetBytes < 0 || $offsetBytes >= $fileSize) {
            return $offsetBytes === 0 ? 0 : null;
        }
        return (int) min(self::SLICES - 1, floor($offsetBytes * self::SLICES / $fileSize));
    }
}
