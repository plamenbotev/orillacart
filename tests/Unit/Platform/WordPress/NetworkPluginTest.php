<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\WordPress;

use PHPUnit\Framework\TestCase;

/**
 * OrillaCart Network (network.php) ships in the shop's folder and may define the version before the shop does.
 */
final class NetworkPluginTest extends TestCase
{
    public function testItHasTheShopsVersion(): void
    {
        $root = \dirname(__DIR__, 4);
        $shop = (string) file_get_contents($root . '/orillacart.php');
        $network = (string) file_get_contents($root . '/network.php');

        self::assertSame(1, preg_match("/define\\('ORILLACART_VERSION', '([^']+)'\\)/", $shop, $version));
        self::assertStringContainsString("define('ORILLACART_VERSION', '" . $version[1] . "')", $network);
        self::assertSame(1, preg_match('/^ \* Version:\s+' . preg_quote($version[1], '/') . '$/m', $network));
        self::assertSame(1, preg_match('/^ \* Network:\s+true$/m', $network));
    }
}
