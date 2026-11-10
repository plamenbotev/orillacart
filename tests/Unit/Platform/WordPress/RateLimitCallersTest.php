<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\WordPress;

use OrillaCart\Platform\WordPress\Security\TransientRateLimiter;
use PHPUnit\Framework\TestCase;

/** One caller is one network: an IPv6 /64 is counted as one, whichever of its addresses it sends from. */
final class RateLimitCallersTest extends TestCase
{
    public function testAnIpv6CallerIsCountedByItsSlash64(): void
    {
        self::assertSame(TransientRateLimiter::network('ip:2001:db8:1:2::1'), TransientRateLimiter::network('ip:2001:db8:1:2:ffff:ffff:ffff:fffe'));
        self::assertNotSame(TransientRateLimiter::network('ip:2001:db8:1:2::1'), TransientRateLimiter::network('ip:2001:db8:1:3::1'));
        self::assertSame('ip:203.0.113.5', TransientRateLimiter::network('ip:203.0.113.5'));
        self::assertSame('cart:abc', TransientRateLimiter::network('cart:abc'));
    }
}
