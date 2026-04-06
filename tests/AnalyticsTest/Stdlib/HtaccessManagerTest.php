<?php declare(strict_types=1);

namespace AnalyticsTest\Stdlib;

use Analytics\Stdlib\HtaccessManager;
use PHPUnit\Framework\TestCase;

class HtaccessManagerTest extends TestCase
{
    private HtaccessManager $manager;

    protected function setUp(): void
    {
        $this->manager = new HtaccessManager();
    }

    private function baseHtaccess(string $extra = ''): string
    {
        return "RewriteEngine On\n\n" . $extra
            . "RewriteCond %{REQUEST_FILENAME} -f\n"
            . "RewriteRule .* - [L]\n"
            . "RewriteRule ^(.*)$ index.php [L]\n";
    }

    public function testParseAccessTypesNone(): void
    {
        $this->assertSame([], $this->manager->parseAccessTypes($this->baseHtaccess()));
    }

    public function testParseAccessTypesWithRule(): void
    {
        $access = "# Module Access: protect files.\n"
            . "RewriteRule \"^files/(original|large)/(.*)$\" \"access/files/\$1/\$2\" [NC,L]\n\n";
        $types = $this->manager->parseAccessTypes($this->baseHtaccess($access));
        $this->assertSame(['original', 'large'], $types);
    }

    public function testParseAccessTypesWithComment(): void
    {
        $access = "# Module Access: protect files.\n"
            . "# This rule is automatically managed by the module.\n"
            . "RewriteRule \"^files/(original|large|medium)/(.*)$\" \"access/files/\$1/\$2\" [NC,L]\n\n";
        $types = $this->manager->parseAccessTypes($this->baseHtaccess($access));
        $this->assertSame(['original', 'large', 'medium'], $types);
    }

    public function testParseManagedAnalyticsTypesNone(): void
    {
        $this->assertSame([], $this->manager->parseManagedAnalyticsTypes($this->baseHtaccess()));
    }

    public function testParseManagedAnalyticsTypesWithRule(): void
    {
        $block = "# Module Analytics: count downloads.\n"
            . "# This rule is automatically managed by the module.\n"
            . "RewriteRule \"^files/(medium|square)/(.*)$\" \"download/files/\$1/\$2\" [NC,L]\n\n";
        $types = $this->manager->parseManagedAnalyticsTypes($this->baseHtaccess($block));
        $this->assertSame(['medium', 'square'], $types);
    }

    public function testParseLegacyAnalyticsTypesAlternation(): void
    {
        $legacy = "RewriteRule ^files/(original|large)/(.*)$ /download/files/\$1/\$2 [NC,L]\n";
        $r = $this->manager->parseLegacyAnalyticsTypes($this->baseHtaccess($legacy));
        $this->assertTrue($r['found']);
        $this->assertSame(['original', 'large'], $r['types']);
    }

    public function testParseLegacyAnalyticsTypesPerType(): void
    {
        $legacy = "RewriteRule \"^files/original/(.*)$\" \"/download/files/original/\$1\" [NC,L]\n"
            . "RewriteRule \"^files/large/(.*)$\" \"/download/files/large/\$1\" [NC,L]\n";
        $r = $this->manager->parseLegacyAnalyticsTypes($this->baseHtaccess($legacy));
        $this->assertTrue($r['found']);
        $this->assertSame(['original', 'large'], $r['types']);
    }

    public function testParseLegacyAnalyticsTypesIgnoredWhenManagedPresent(): void
    {
        $managed = "# Module Analytics: count downloads.\n"
            . "RewriteRule \"^files/(medium)/(.*)$\" \"download/files/\$1/\$2\" [NC,L]\n";
        $r = $this->manager->parseLegacyAnalyticsTypes($this->baseHtaccess($managed));
        $this->assertFalse($r['found']);
    }

    public function testParseLegacyAnalyticsTypesNoneOnPlainHtaccess(): void
    {
        $r = $this->manager->parseLegacyAnalyticsTypes($this->baseHtaccess());
        $this->assertFalse($r['found']);
        $this->assertSame([], $r['types']);
    }

    public function testComputeAnalyticsTypesSubtractsAccess(): void
    {
        $this->assertSame(
            ['medium', 'square'],
            $this->manager->computeAnalyticsTypes(['original', 'large', 'medium', 'square'], ['original', 'large'])
        );
    }

    public function testComputeAnalyticsTypesEmptyWhenAllCovered(): void
    {
        $this->assertSame(
            [],
            $this->manager->computeAnalyticsTypes(['original', 'large'], ['original', 'large'])
        );
    }

    public function testComputeAnalyticsTypesNoAccess(): void
    {
        $this->assertSame(
            ['original', 'medium'],
            $this->manager->computeAnalyticsTypes(['original', 'medium'], [])
        );
    }

    public function testComputeAnalyticsTypesWithCustom(): void
    {
        $this->assertSame(
            ['mp3', 'pdf'],
            $this->manager->computeAnalyticsTypes(['original', 'mp3', 'pdf'], ['original', 'large'])
        );
    }

    public function testComputeAbsorbedTypes(): void
    {
        $this->assertSame(
            ['original', 'large'],
            $this->manager->computeAbsorbedTypes(['original', 'large', 'medium'], ['original', 'large'])
        );
    }

    public function testBuildBlockEmpty(): void
    {
        $this->assertSame('', $this->manager->buildBlock([]));
    }

    public function testBuildBlockSingle(): void
    {
        $expected = "# Module Analytics: count downloads.\n"
            . "# This rule is automatically managed by the module.\n"
            . 'RewriteRule "^files/(medium)/(.*)$" "download/files/$1/$2" [NC,L]';
        $this->assertSame($expected, $this->manager->buildBlock(['medium']));
    }

    public function testBuildBlockMultiple(): void
    {
        $block = $this->manager->buildBlock(['original', 'medium', 'mp3']);
        $this->assertStringContainsString('(original|medium|mp3)', $block);
        $this->assertStringContainsString('download/files/$1/$2', $block);
        $this->assertStringEndsWith('[NC,L]', $block);
    }

    public function testApplyInsertsBlockAfterRewriteEngine(): void
    {
        $h = $this->baseHtaccess();
        $r = $this->manager->apply($h, ['medium']);
        $this->assertStringContainsString('# Module Analytics: count downloads.', $r);
        // Block must appear after RewriteEngine On.
        $posEngine = strpos($r, 'RewriteEngine On');
        $posBlock = strpos($r, '# Module Analytics: count downloads.');
        $this->assertGreaterThan($posEngine, $posBlock);
    }

    public function testApplyEmptyTypesRemovesExistingBlock(): void
    {
        $existing = "# Module Analytics: count downloads.\n"
            . "# This rule is automatically managed by the module.\n"
            . "RewriteRule \"^files/(medium)/(.*)$\" \"download/files/\$1/\$2\" [NC,L]\n\n";
        $h = $this->baseHtaccess($existing);
        $r = $this->manager->apply($h, []);
        $this->assertStringNotContainsString('# Module Analytics:', $r);
    }

    public function testApplyReplacesExistingBlock(): void
    {
        $existing = "# Module Analytics: count downloads.\n"
            . "# This rule is automatically managed by the module.\n"
            . "RewriteRule \"^files/(original|large)/(.*)$\" \"download/files/\$1/\$2\" [NC,L]\n\n";
        $h = $this->baseHtaccess($existing);
        $r = $this->manager->apply($h, ['medium', 'square']);
        $this->assertStringNotContainsString('(original|large)', $r);
        $this->assertStringContainsString('(medium|square)', $r);
        // Only one Analytics block remains.
        $this->assertSame(1, substr_count($r, '# Module Analytics: count downloads.'));
    }

    public function testApplyRemovesLegacyRules(): void
    {
        $legacy = "RewriteRule \"^files/original/(.*)$\" \"/download/files/original/\$1\" [NC,L]\n"
            . "RewriteRule \"^files/large/(.*)$\" \"/download/files/large/\$1\" [NC,L]\n";
        $h = $this->baseHtaccess($legacy);
        $r = $this->manager->apply($h, ['medium'], true);
        $this->assertStringNotContainsString('/download/files/original/', $r);
        $this->assertStringNotContainsString('/download/files/large/', $r);
        $this->assertStringContainsString('(medium)', $r);
    }

    public function testApplyPreservesAccessRule(): void
    {
        $access = "# Module Access: protect files.\n"
            . "RewriteRule \"^files/(original|large)/(.*)$\" \"access/files/\$1/\$2\" [NC,L]\n\n";
        $h = $this->baseHtaccess($access);
        $r = $this->manager->apply($h, ['medium']);
        $this->assertStringContainsString('# Module Access: protect files.', $r);
        $this->assertStringContainsString('access/files/$1/$2', $r);
        $this->assertStringContainsString('# Module Analytics: count downloads.', $r);
        $this->assertStringContainsString('(medium)', $r);
    }

    public function testRoundTripPreservesAccessTypes(): void
    {
        $access = "# Module Access: protect files.\n"
            . "RewriteRule \"^files/(original|large)/(.*)$\" \"access/files/\$1/\$2\" [NC,L]\n\n";
        $h = $this->baseHtaccess($access);
        $accessTypes = $this->manager->parseAccessTypes($h);
        $analyticsTypes = $this->manager->computeAnalyticsTypes(['original', 'large', 'medium'], $accessTypes);
        $h2 = $this->manager->apply($h, $analyticsTypes);
        // Access rule still tracks original|large.
        $this->assertSame(['original', 'large'], $this->manager->parseAccessTypes($h2));
        // Analytics rule tracks only medium (original|large absorbed).
        $this->assertSame(['medium'], $this->manager->parseManagedAnalyticsTypes($h2));
    }

    public function testRoundTripAllTypesAbsorbed(): void
    {
        $access = "# Module Access: protect files.\n"
            . "RewriteRule \"^files/(original|large|medium|square)/(.*)$\" \"access/files/\$1/\$2\" [NC,L]\n\n";
        $h = $this->baseHtaccess($access);
        $accessTypes = $this->manager->parseAccessTypes($h);
        $analyticsTypes = $this->manager->computeAnalyticsTypes(['original', 'medium'], $accessTypes);
        $this->assertSame([], $analyticsTypes);
        $h2 = $this->manager->apply($h, $analyticsTypes);
        // No Analytics block written.
        $this->assertStringNotContainsString('# Module Analytics:', $h2);
        // Access rule untouched.
        $this->assertSame(['original', 'large', 'medium', 'square'], $this->manager->parseAccessTypes($h2));
    }
}
