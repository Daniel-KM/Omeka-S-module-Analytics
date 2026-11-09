<?php declare(strict_types=1);

namespace AnalyticsTest\Stdlib;

use Analytics\Stdlib\HitDeduplicator;
use PHPUnit\Framework\TestCase;

class HitDeduplicatorTest extends TestCase
{
    const WINDOW = 1800;

    public function testBurstOfSameVisitorKeepsFirstHitOnly(): void
    {
        $deduplicator = new HitDeduplicator(self::WINDOW);
        $this->assertFalse($deduplicator->isDuplicate('/files/a.mp4', '1.2.3.4', 'ua-1', 'fr', '2026-10-25 10:00:00'));
        $this->assertTrue($deduplicator->isDuplicate('/files/a.mp4', '1.2.3.4', 'ua-1', 'fr', '2026-10-25 10:00:01'));
        $this->assertTrue($deduplicator->isDuplicate('/files/a.mp4', '1.2.3.4', 'ua-1', 'fr', '2026-10-25 10:20:00'));
    }

    public function testReplayAfterWindowIsKept(): void
    {
        $deduplicator = new HitDeduplicator(self::WINDOW);
        $this->assertFalse($deduplicator->isDuplicate('/files/a.mp4', '1.2.3.4', 'ua-1', 'fr', '2026-10-25 10:00:00'));
        $this->assertFalse($deduplicator->isDuplicate('/files/a.mp4', '1.2.3.4', 'ua-1', 'fr', '2026-10-25 10:30:00'));
    }

    public function testWindowStartsFromLastKeptHitNotFromLastDuplicate(): void
    {
        $deduplicator = new HitDeduplicator(self::WINDOW);
        $this->assertFalse($deduplicator->isDuplicate('/files/a.mp4', '1.2.3.4', 'ua-1', 'fr', '2026-10-25 10:00:00'));
        $this->assertTrue($deduplicator->isDuplicate('/files/a.mp4', '1.2.3.4', 'ua-1', 'fr', '2026-10-25 10:20:00'));
        // 40 minutes after the kept hit, even if 20 minutes after a duplicate.
        $this->assertFalse($deduplicator->isDuplicate('/files/a.mp4', '1.2.3.4', 'ua-1', 'fr', '2026-10-25 10:40:00'));
    }

    public function testSameIpButOtherUserAgentIsKept(): void
    {
        // Visitors sharing an ip: untrusted reverse proxy, shared network.
        $deduplicator = new HitDeduplicator(self::WINDOW);
        $this->assertFalse($deduplicator->isDuplicate('/files/a.mp4', '1.2.3.4', 'ua-1', 'fr', '2026-10-25 10:00:00'));
        $this->assertFalse($deduplicator->isDuplicate('/files/a.mp4', '1.2.3.4', 'ua-2', 'fr', '2026-10-25 10:00:01'));
        $this->assertTrue($deduplicator->isDuplicate('/files/a.mp4', '1.2.3.4', 'ua-2', 'fr', '2026-10-25 10:00:02'));
    }

    public function testSameUserAgentButOtherLanguageIsKept(): void
    {
        // With an anonymized ip, the language refines the detection of the
        // visitors sharing a common browser.
        $deduplicator = new HitDeduplicator(self::WINDOW);
        $this->assertFalse($deduplicator->isDuplicate('/files/a.mp4', '::', 'ua-1', 'fr', '2026-10-25 10:00:00'));
        $this->assertFalse($deduplicator->isDuplicate('/files/a.mp4', '::', 'ua-1', 'de', '2026-10-25 10:00:01'));
        $this->assertTrue($deduplicator->isDuplicate('/files/a.mp4', '::', 'ua-1', 'de', '2026-10-25 10:00:02'));
    }

    public function testOtherVisitorOrOtherFileIsKept(): void
    {
        $deduplicator = new HitDeduplicator(self::WINDOW);
        $this->assertFalse($deduplicator->isDuplicate('/files/a.mp4', '1.2.3.4', 'ua-1', 'fr', '2026-10-25 10:00:00'));
        $this->assertFalse($deduplicator->isDuplicate('/files/a.mp4', '5.6.7.8', 'ua-1', 'fr', '2026-10-25 10:00:01'));
        $this->assertFalse($deduplicator->isDuplicate('/files/b.mp4', '5.6.7.8', 'ua-1', 'fr', '2026-10-25 10:00:02'));
    }
}
