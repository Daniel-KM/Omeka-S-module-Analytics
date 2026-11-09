<?php declare(strict_types=1);

namespace AnalyticsTest\Api\Adapter;

use Analytics\Api\Adapter\HitAdapter;
use Laminas\ServiceManager\ServiceManager;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The ip is resolved with the trusted proxies of the main settings.
 */
class ClientIpTest extends TestCase
{
    protected $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    protected function adapter(array $trustedProxies = []): HitAdapter
    {
        $settings = $this->createMock(Settings::class);
        $clientIpClass = class_exists(\Omeka\Stdlib\ClientIp::class) ? \Omeka\Stdlib\ClientIp::class : \Common\Stdlib\ClientIp::class;
        $services = new ServiceManager([
            'services' => [
                'Omeka\Settings' => $settings,
                'Omeka\ClientIp' => new $clientIpClass($trustedProxies),
                'EventManager' => new \Laminas\EventManager\EventManager(),
            ],
        ]);
        $adapter = new HitAdapter();
        $adapter->setServiceLocator($services);
        return $adapter;
    }

    public function testTrustedProxies(): void
    {
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1, 203.0.113.50';
        $this->assertSame('203.0.113.50', $this->adapter(['192.0.2.10'])->getClientIp());
        $this->assertSame('192.0.2.10', $this->adapter(['10.0.0.1'])->getClientIp());
    }
}
