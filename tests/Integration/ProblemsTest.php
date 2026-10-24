<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Platform\WordPress\Logging\DebugLogLogger;
use OrillaCart\Platform\WordPress\Logging\ShopLogger;

/**
 * The shop's warnings and errors reach the shop manager: kept once each with how often, listed on the Status
 * screen (GET /problems) and cleared there; notices and below stay in debug.log only.
 */
final class ProblemsTest extends RestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_problems");
    }

    public function testWarningsAreKeptOnceEachAndListedForTheShop(): void
    {
        $lines = [];
        $logger = new ShopLogger(new DebugLogLogger(false, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));
        $logger->warning('Courier {courier} did not answer', ['courier' => 'DPD']);
        $logger->warning('Courier {courier} did not answer', ['courier' => 'DPD']);
        $logger->error('A refund went wrong', ['exception' => new \RuntimeException('PayPal said no')]);
        $logger->notice('Only for debug.log');
        $logger->flush();
        self::assertCount(4, $lines, 'debug.log hears all of them');

        $this->actAs('administrator');
        $page = $this->data($this->request('GET', '/problems'));
        self::assertIsArray($page['problems']);
        $problems = array_map(static fn(mixed $problem): array => \is_array($problem) ? [$problem['level'], $problem['message'], $problem['count']] : ['', '', 0], $page['problems']);
        usort($problems, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        self::assertSame([['error', 'A refund went wrong (RuntimeException: PayPal said no)', 1], ['warning', 'Courier DPD did not answer', 2]], $problems);

        self::assertSame(204, $this->request('DELETE', '/problems', nonce: true)->get_status());
        self::assertSame(0, $this->data($this->request('GET', '/problems'))['total']);

        wp_set_current_user(0);
        self::assertSame(401, $this->request('GET', '/problems')->get_status());
    }
}
