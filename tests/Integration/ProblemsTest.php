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

    public function testAProblemIsKeptOnTheSiteItHappenedOnThoughItsLoggerIsGoneBeforeTheEnd(): void
    {
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        // Another site of a network, as a network worker switches to it: its own tables under its own prefix.
        $prefix = $wpdb->prefix;
        // What earlier tests logged and is still waiting goes first, so only this test's problems are counted.
        ShopLogger::flushAll();
        $wpdb->query("DELETE FROM {$prefix}orillacart_problems");
        $other = $prefix . 'otherproblems_';
        $wpdb->query("CREATE TABLE {$other}orillacart_problems LIKE {$prefix}orillacart_problems");
        try {
            $quiet = new DebugLogLogger(false, static function (): void {});
            $wpdb->set_prefix($other);
            $there = new ShopLogger($quiet);
            $there->warning('On the other site');
            $wpdb->set_prefix($prefix);
            // The worker is done with that site's container, and its logger with it.
            unset($there);
            new ShopLogger($quiet)->warning('On this site');

            ShopLogger::flushAll();

            self::assertSame(['On the other site'], $wpdb->get_col("SELECT message FROM {$other}orillacart_problems"));
            self::assertSame(['On this site'], $wpdb->get_col("SELECT message FROM {$prefix}orillacart_problems"));
        } finally {
            $wpdb->set_prefix($prefix);
            $wpdb->query("DROP TABLE IF EXISTS {$other}orillacart_problems");
        }
    }
}
