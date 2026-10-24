<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shared;

use OrillaCart\Shared\Http\Security\ClientAddress;
use PHPUnit\Framework\TestCase;

final class ClientAddressTest extends TestCase
{
    private const array PROXIES = ['10.0.0.0/8', '2001:db8::/32'];

    public function testWithoutTrustedProxiesTheHeadersAreIgnored(): void
    {
        self::assertSame('203.0.113.7', ClientAddress::of(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1'], []), 'a visitor does not choose their own address');
        self::assertSame('unknown', ClientAddress::of([], []));
    }

    public function testBehindATrustedProxyTheVisitorIsTheLastHopNotOfItsProxies(): void
    {
        self::assertSame('203.0.113.7', ClientAddress::of(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9, 203.0.113.7, 10.4.5.6'], self::PROXIES), 'a hop the visitor sent first is passed over');
        self::assertSame('198.51.100.20', ClientAddress::of(['REMOTE_ADDR' => '2001:db8::1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.20'], self::PROXIES, ['2001:db8::/32']), 'from Cloudflare');
        self::assertSame('192.0.2.1', ClientAddress::of(['REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'], self::PROXIES), 'not from a trusted proxy');
        self::assertSame('10.1.2.3', ClientAddress::of(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => 'not an address'], self::PROXIES));
    }

    public function testCloudflaresHeaderCountsOnlyFromCloudflare(): void
    {
        $spoofed = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_CF_CONNECTING_IP' => '6.6.6.6', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.9'];
        self::assertSame('203.0.113.9', ClientAddress::of($spoofed, ['10.0.0.0/8']), 'nginx or a load balancer passes the visitor\'s own on');
        self::assertSame('198.51.100.20', ClientAddress::of(['REMOTE_ADDR' => '172.64.1.2', 'HTTP_CF_CONNECTING_IP' => '198.51.100.20'], ['172.64.0.0/13']), 'one of Cloudflare\'s published addresses');
    }
}
