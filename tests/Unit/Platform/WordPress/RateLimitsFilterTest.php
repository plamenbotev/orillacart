<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\WordPress;

use OrillaCart\Platform\WordPress\Security\TransientRateLimiter;
use PHPUnit\Framework\TestCase;

/** The filter of the rate limits can raise or lower the shop's limits, not switch them off. */
final class RateLimitsFilterTest extends TestCase
{
    public function testAFilterChangesALimit(): void
    {
        self::assertSame([5, 60], TransientRateLimiter::limitOf(['extension_emails' => [5, 60]], 'extension_emails'));
    }

    public function testAGuardTheFilterDropsOrBreaksKeepsTheShopsLimit(): void
    {
        $own = TransientRateLimiter::limits()['extension_emails'];

        self::assertSame($own, TransientRateLimiter::limitOf([], 'extension_emails'), 'dropped');
        self::assertSame($own, TransientRateLimiter::limitOf(['extension_emails' => null], 'extension_emails'), 'emptied');
        self::assertSame($own, TransientRateLimiter::limitOf(['extension_emails' => [50, 0]], 'extension_emails'), 'no window');
        self::assertSame($own, TransientRateLimiter::limitOf(['extension_emails' => ['50', 3600]], 'extension_emails'), 'not a number');
        self::assertSame($own, TransientRateLimiter::limitOf(false, 'extension_emails'), 'the filter gave no list');
    }

    public function testAGuardOfAnAddOnIsCountedOnlyWithALimit(): void
    {
        self::assertNull(TransientRateLimiter::limitOf([], 'my_addon'));
        self::assertSame([3, 60], TransientRateLimiter::limitOf(['my_addon' => [3, 60]], 'my_addon'));
    }
}
