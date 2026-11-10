<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\WordPress;

use OrillaCart\Platform\WordPress\Logging\DebugLogHealth;
use PHPUnit\Framework\TestCase;

final class DebugLogHealthTest extends TestCase
{
    public function testTheLogIsWhereWordPressPutsIt(): void
    {
        self::assertSame('/srv/site/wp-content/debug.log', DebugLogHealth::logFile(true, '/srv/site/wp-content', ''));
        self::assertSame('/var/log/shop.log', DebugLogHealth::logFile('/var/log/shop.log', '/srv/site/wp-content', ''));
        self::assertSame('/srv/site/php-errors.log', DebugLogHealth::logFile(false, '/srv/site/wp-content', '/srv/site/php-errors.log'));
        self::assertNull(DebugLogHealth::logFile(false, '/srv/site/wp-content', ''));
        self::assertNull(DebugLogHealth::logFile(false, '/srv/site/wp-content', 'syslog'));
        self::assertNull(DebugLogHealth::logFile(false, '/srv/site/wp-content', 'php://stderr'));
    }

    public function testALogInsideTheSiteHasAnAddressAndOneOutsideHasNone(): void
    {
        self::assertSame('https://shop.example/wp-content/debug.log', DebugLogHealth::webAddress('/srv/site/wp-content/debug.log', '/srv/site/', 'https://shop.example/'));
        self::assertSame('https://shop.example/logs/my%20shop.log', DebugLogHealth::webAddress('/srv/site/logs/my shop.log', '/srv/site', 'https://shop.example'));
        self::assertNull(DebugLogHealth::webAddress('/var/log/shop.log', '/srv/site/', 'https://shop.example/'));
        self::assertNull(DebugLogHealth::webAddress('/srv/site/../debug.log', '/srv/site/', 'https://shop.example/'));
        self::assertNull(DebugLogHealth::webAddress('/srv/site-other/debug.log', '/srv/site', 'https://shop.example/'));
    }
}
