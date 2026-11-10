<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\WordPress;

use OrillaCart\Platform\WordPress\Security\TransientRateLimiter;
use PHPUnit\Framework\TestCase;

/** One caller is one network: an IPv6 /64 is counted as one, whichever of its addresses it sends from; an IPv4 one is its address. */
final class RateLimitCallersTest extends TestCase
{
    public function testAnIpv6CallerIsCountedByItsSlash64(): void
    {
        self::assertSame(TransientRateLimiter::network('ip:2001:db8:1:2::1'), TransientRateLimiter::network('ip:2001:db8:1:2:ffff:ffff:ffff:fffe'));
        self::assertNotSame(TransientRateLimiter::network('ip:2001:db8:1:2::1'), TransientRateLimiter::network('ip:2001:db8:1:3::1'));
        self::assertSame('ip:203.0.113.5', TransientRateLimiter::network('ip:203.0.113.5'));
        self::assertSame('cart:abc', TransientRateLimiter::network('cart:abc'));
    }

    public function testAnIpv4CallerWrittenAsIpv6IsCountedAsItsOwnAddress(): void
    {
        self::assertSame('ip:203.0.113.5', TransientRateLimiter::network('ip:::ffff:203.0.113.5'));
        self::assertSame('ip:198.51.100.7', TransientRateLimiter::network('ip:::ffff:198.51.100.7'), 'not the same /64 as every other IPv4 caller');
        self::assertSame('ip:192.0.2.1', TransientRateLimiter::network('ip:64:ff9b::c000:201'), 'through NAT64');
    }

    public function testTheNetworkAroundACallerIsItsSlash48OrSlash24(): void
    {
        self::assertSame(TransientRateLimiter::network('net:2001:db8:1:2::1'), TransientRateLimiter::network('net:2001:db8:1:ffff::1'), 'every /64 of a /48');
        self::assertNotSame(TransientRateLimiter::network('net:2001:db8:1::1'), TransientRateLimiter::network('net:2001:db8:2::1'));
        self::assertSame('net:203.0.113.0/24', TransientRateLimiter::network('net:203.0.113.77'));
        self::assertSame('net:203.0.113.0/24', TransientRateLimiter::network('net:::ffff:203.0.113.9'));
    }
}
