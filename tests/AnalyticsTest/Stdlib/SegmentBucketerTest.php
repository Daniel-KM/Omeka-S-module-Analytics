<?php declare(strict_types=1);

namespace AnalyticsTest\Stdlib;

use Analytics\Stdlib\SegmentBucketer;
use PHPUnit\Framework\TestCase;

class SegmentBucketerTest extends TestCase
{
    /**
     * @var SegmentBucketer
     */
    protected $bucketer;

    protected function setUp(): void
    {
        $this->bucketer = new SegmentBucketer();
    }

    public function testTileCoversItsCells(): void
    {
        // A 256px tile at the origin of a 4096 × 4096 image covers one cell of
        // the 16 × 16 grid (each cell is 256 × 256).
        $this->assertSame([0], $this->bucketer->regionCells(0, 0, 256, 256, 4096, 4096));
        // The second cell of the second row.
        $this->assertSame([17], $this->bucketer->regionCells(256, 256, 256, 256, 4096, 4096));
        // A 512px region covers four cells.
        $this->assertSame([0, 1, 16, 17], $this->bucketer->regionCells(0, 0, 512, 512, 4096, 4096));
    }

    public function testFullImageIsNotAZone(): void
    {
        $this->assertSame([], $this->bucketer->regionCells(0, 0, 4096, 4096, 4096, 4096));
        $this->assertSame([], $this->bucketer->regionCells(0, 0, 5000, 5000, 4096, 4096));
    }

    public function testRegionIsClampedToTheImage(): void
    {
        // An edge tile larger than the remaining pixels stays in the grid.
        $cells = $this->bucketer->regionCells(3840, 3840, 512, 512, 4000, 4000);
        $this->assertSame([255], $cells);
        // A region out of the image is invalid.
        $this->assertSame([], $this->bucketer->regionCells(5000, 0, 256, 256, 4096, 4096));
    }

    public function testTimeSlice(): void
    {
        $this->assertSame(0, $this->bucketer->timeSlice(0, 1000));
        $this->assertSame(10, $this->bucketer->timeSlice(500, 1000));
        $this->assertSame(19, $this->bucketer->timeSlice(999, 1000));
        // Start of the file, even without size (missing file).
        $this->assertSame(0, $this->bucketer->timeSlice(0, 0));
        $this->assertNull($this->bucketer->timeSlice(500, 0));
        $this->assertNull($this->bucketer->timeSlice(1000, 1000));
    }
}
