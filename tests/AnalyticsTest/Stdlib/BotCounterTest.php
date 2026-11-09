<?php declare(strict_types=1);

namespace AnalyticsTest\Stdlib;

use Analytics\Stdlib\BotCounter;
use PHPUnit\Framework\TestCase;

class BotCounterTest extends TestCase
{
    /**
     * @dataProvider providerNames
     */
    public function testName(?string $userAgent, string $expected): void
    {
        $this->assertSame($expected, BotCounter::name($userAgent));
    }

    public function providerNames(): array
    {
        return [
            'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'Googlebot'],
            'bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', 'bingbot'],
            'gptbot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.1; +https://openai.com/gptbot)', 'GPTBot'],
            'crawler' => ['MyCrawler/1.0', 'MyCrawler'],
            'slurp' => ['Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)', 'Slurp'],
            'browser' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', ''],
            'empty' => ['', ''],
            'null' => [null, ''],
        ];
    }

    public function testNameIsLimitedToTheSizeOfTheColumn(): void
    {
        $this->assertSame(BotCounter::NAME_MAX_LENGTH, strlen(BotCounter::name(str_repeat('a', 100) . 'bot')));
    }

    public function testEstimate(): void
    {
        $this->assertSame(85, BotCounter::estimate([
            BotCounter::TYPE_CHALLENGED => 100,
            BotCounter::TYPE_SOLVED => 20,
            BotCounter::TYPE_DECLARED => 5,
        ]));
    }

    public function testEstimateIsNeverNegative(): void
    {
        $this->assertSame(0, BotCounter::estimate([
            BotCounter::TYPE_CHALLENGED => 1,
            BotCounter::TYPE_SOLVED => 3,
        ]));
        $this->assertSame(0, BotCounter::estimate([]));
    }
}
