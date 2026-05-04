<?php declare(strict_types=1);

namespace AnalyticsTest\Stdlib;

use Analytics\Stdlib\IpResolver;
use PHPUnit\Framework\TestCase;

class IpResolverTest extends TestCase
{
    public function testNoTrustReturnsRemoteAddr(): void
    {
        $r = new IpResolver('');
        $ip = $r->resolve([
            'REMOTE_ADDR' => '203.0.113.5',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.1',
            'HTTP_X_REAL_IP' => '198.51.100.2',
        ]);
        $this->assertSame('203.0.113.5', $ip);
    }

    public function testUntrustedRemoteIgnoresProxyHeaders(): void
    {
        $r = new IpResolver('10.0.0.0/8');
        $ip = $r->resolve([
            'REMOTE_ADDR' => '203.0.113.5',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ]);
        $this->assertSame('203.0.113.5', $ip);
    }

    public function testTrustedRemoteHonorsXForwardedFor(): void
    {
        $r = new IpResolver('10.0.0.1');
        $ip = $r->resolve([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.42',
        ]);
        $this->assertSame('198.51.100.42', $ip);
    }

    public function testTrustedCidrHonorsHeader(): void
    {
        $r = new IpResolver('10.0.0.0/8, 192.168.0.0/16');
        $ip = $r->resolve([
            'REMOTE_ADDR' => '10.20.30.40',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.42',
        ]);
        $this->assertSame('198.51.100.42', $ip);
    }

    public function testChainSkipsTrustedHops(): void
    {
        $r = new IpResolver('10.0.0.0/8');
        $ip = $r->resolve([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.42, 10.0.0.7, 10.0.0.8',
        ]);
        $this->assertSame('198.51.100.42', $ip);
    }

    public function testFullyTrustedChainReturnsLeftmost(): void
    {
        $r = new IpResolver('10.0.0.0/8');
        $ip = $r->resolve([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '10.1.1.1, 10.2.2.2',
        ]);
        $this->assertSame('10.1.1.1', $ip);
    }

    public function testEmptyXForwardedForFallsBackToXRealIp(): void
    {
        $r = new IpResolver('10.0.0.1');
        $ip = $r->resolve([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '',
            'HTTP_X_REAL_IP' => '198.51.100.10',
        ]);
        $this->assertSame('198.51.100.10', $ip);
    }

    public function testEmptyHeadersFallBackToRemote(): void
    {
        $r = new IpResolver('10.0.0.1');
        $ip = $r->resolve([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '',
            'HTTP_X_REAL_IP' => '',
        ]);
        $this->assertSame('10.0.0.1', $ip);
    }

    public function testInvalidForwardedIpsSkipped(): void
    {
        $r = new IpResolver('10.0.0.1');
        $ip = $r->resolve([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => 'not-an-ip, 198.51.100.5',
        ]);
        $this->assertSame('198.51.100.5', $ip);
    }

    public function testInvalidRemoteReturnsSentinel(): void
    {
        $r = new IpResolver('');
        $ip = $r->resolve(['REMOTE_ADDR' => 'garbage']);
        $this->assertSame('::', $ip);
    }

    public function testMissingRemoteReturnsSentinel(): void
    {
        $r = new IpResolver('');
        $this->assertSame('::', $r->resolve([]));
    }

    public function testIpv6Remote(): void
    {
        $r = new IpResolver('::1');
        $ip = $r->resolve([
            'REMOTE_ADDR' => '::1',
            'HTTP_X_FORWARDED_FOR' => '2001:db8::1',
        ]);
        $this->assertSame('2001:db8::1', $ip);
    }

    public function testIpv6CidrRange(): void
    {
        $r = new IpResolver('2001:db8::/32');
        $ip = $r->resolve([
            'REMOTE_ADDR' => '2001:db8:abcd::1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.5',
        ]);
        $this->assertSame('198.51.100.5', $ip);
    }

    public function testIpv4CidrDoesNotMatchIpv6(): void
    {
        $r = new IpResolver('10.0.0.0/8');
        $this->assertFalse($r->isTrusted('::1'));
    }

    public function testInvalidProxyEntriesIgnored(): void
    {
        $r = new IpResolver('garbage, 10.0.0.0/99, 10.0.0.1');
        $this->assertTrue($r->isTrusted('10.0.0.1'));
        $this->assertFalse($r->isTrusted('garbage'));
    }

    public function testCidrZeroPrefixMatchesAll(): void
    {
        $r = new IpResolver('0.0.0.0/0');
        $this->assertTrue($r->isTrusted('1.2.3.4'));
    }

    public function testDetectNoProxyHeaders(): void
    {
        $r = new IpResolver('');
        $d = $r->detect(['REMOTE_ADDR' => '203.0.113.5']);
        $this->assertSame('no_proxy', $d['status']);
        $this->assertFalse($d['hasProxyHeaders']);
    }

    public function testDetectProxyLikelyFromPrivatePeer(): void
    {
        $r = new IpResolver('');
        $d = $r->detect([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.5',
        ]);
        $this->assertSame('proxy_likely', $d['status']);
        $this->assertTrue($d['remoteIsPrivate']);
    }

    public function testDetectProxyLikelyFromLoopback(): void
    {
        $r = new IpResolver('');
        $d = $r->detect([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_REAL_IP' => '198.51.100.5',
        ]);
        $this->assertSame('proxy_likely', $d['status']);
    }

    public function testDetectProxyOk(): void
    {
        $r = new IpResolver('10.0.0.1');
        $d = $r->detect([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.5',
        ]);
        $this->assertSame('proxy_ok', $d['status']);
        $this->assertTrue($d['remoteIsTrusted']);
    }

    public function testDetectProxyMisconfigured(): void
    {
        $r = new IpResolver('192.168.1.1');
        $d = $r->detect([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.5',
        ]);
        $this->assertSame('proxy_misconfigured', $d['status']);
    }

    public function testDetectSpoofSuspectedFromPublicPeer(): void
    {
        $r = new IpResolver('');
        $d = $r->detect([
            'REMOTE_ADDR' => '203.0.113.5',
            'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
        ]);
        $this->assertSame('proxy_spoof_suspected', $d['status']);
    }

    public function testIsPrivateIp(): void
    {
        $r = new IpResolver('');
        $this->assertTrue($r->isPrivateIp('10.0.0.1'));
        $this->assertTrue($r->isPrivateIp('192.168.1.1'));
        $this->assertTrue($r->isPrivateIp('172.16.0.1'));
        $this->assertTrue($r->isPrivateIp('127.0.0.1'));
        $this->assertTrue($r->isPrivateIp('::1'));
        $this->assertTrue($r->isPrivateIp('fc00::1'));
        $this->assertFalse($r->isPrivateIp('203.0.113.5'));
        $this->assertFalse($r->isPrivateIp('8.8.8.8'));
        $this->assertFalse($r->isPrivateIp('garbage'));
    }

    public function testSeparatorVariants(): void
    {
        $r = new IpResolver("10.0.0.1\t10.0.0.2; 10.0.0.3,10.0.0.4");
        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3', '10.0.0.4'] as $ip) {
            $this->assertTrue($r->isTrusted($ip), $ip);
        }
    }
}
