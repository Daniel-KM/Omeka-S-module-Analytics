<?php declare(strict_types=1);

namespace AnalyticsTest\Api\Adapter;

use Analytics\Api\Adapter\HitAdapter;
use PHPUnit\Framework\TestCase;

class PartialIpTest extends TestCase
{
    /**
     * @var HitAdapter
     */
    protected $adapter;

    protected function setUp(): void
    {
        $this->adapter = new HitAdapter();
    }

    public function testIpv4KeepsFirstOctets(): void
    {
        $this->assertSame('192.---.---.---', $this->adapter->partialIp('192.168.56.12', 1));
        $this->assertSame('192.168.---.---', $this->adapter->partialIp('192.168.56.12', 2));
        $this->assertSame('192.168.56.---', $this->adapter->partialIp('192.168.56.12', 3));
    }

    public function testIpv6KeepsFirstGroupsOnly(): void
    {
        // The whole ipv6 was previously kept in partial modes.
        $this->assertSame('2a01:---', $this->adapter->partialIp('2a01:e34:ec2a:94a0::1', 1));
        $this->assertSame('2a01:0e34:---', $this->adapter->partialIp('2a01:e34:ec2a:94a0::1', 2));
        $this->assertSame('2a01:0e34:ec2a:---', $this->adapter->partialIp('2a01:e34:ec2a:94a0::1', 3));
    }

    public function testInvalidIpIsAnonymized(): void
    {
        $this->assertSame('::', $this->adapter->partialIp('not-an-ip:x', 2));
        $this->assertSame('::', $this->adapter->partialIp('10.0.0', 2));
    }
}
