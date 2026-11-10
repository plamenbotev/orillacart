<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Platform\WordPress\Logging\DebugLogLogger;
use OrillaCart\Platform\WordPress\Logging\ShopLogger;
use OrillaCart\Platform\WordPress\Security\SiteSignatures;

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

    public function testAProblemIsOneRowWhateverARequestPutIntoItAndASiteKeepsNoMoreThanItMay(): void
    {
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        ShopLogger::flushAll();
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_problems");
        $logger = new ShopLogger(new DebugLogLogger(false, static function (): void {}));
        $logger->warning('VIES could not be reached: {message}', ['message' => 'timeout 1']);
        $logger->flush();
        $logger->warning('VIES could not be reached: {message}', ['message' => 'timeout 2']);
        $logger->flush();

        self::assertSame([['message' => 'VIES could not be reached: timeout 2', 'count' => '2']], $wpdb->get_results("SELECT message, count FROM {$wpdb->prefix}orillacart_problems", ARRAY_A));

        // A table filled past its limit by something that names every request's problem anew.
        $old = gmdate('Y-m-d H:i:s', time() - 3600);
        for ($i = 0; $i < ShopLogger::MAX_ROWS + 5; $i++) {
            $wpdb->insert("{$wpdb->prefix}orillacart_problems", ['fingerprint' => sha1("old {$i}"), 'level' => 'warning', 'message' => "Old {$i}", 'about' => '', 'count' => 1, 'first_at' => $old, 'last_at' => $old]);
        }
        $logger->warning('The latest');
        $logger->flush();

        self::assertSame((string) ShopLogger::MAX_ROWS, $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}orillacart_problems"));
        self::assertSame('1', $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}orillacart_problems WHERE message = 'The latest'"), 'the ones seen longest ago went');
    }

    public function testAProblemKeepsNoEmailAndGoesWhenThePersonItWasAboutIsErased(): void
    {
        global $wpdb;
        \assert($wpdb instanceof \wpdb);
        ShopLogger::flushAll();
        $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_problems");
        $logger = new ShopLogger(new DebugLogLogger(false, static function (): void {}), SiteSignatures::create());
        $logger->warning('The email {template} to {to} did not go: {reason}', ['template' => 'order_confirmation', 'to' => 'erased-problem@example.com', 'reason' => 'No mailbox for Ada Lovelace']);
        $logger->warning('The courier did not answer');
        $logger->flush();

        self::assertSame(
            ['The courier did not answer', 'The email order_confirmation to [email] did not go: No mailbox for Ada Lovelace'],
            $wpdb->get_col("SELECT message FROM {$wpdb->prefix}orillacart_problems ORDER BY message"),
        );

        $tools = apply_filters('wp_privacy_personal_data_erasers', []);
        self::assertIsArray($tools);
        $tool = $tools['orillacart'] ?? null;
        self::assertIsArray($tool);
        self::assertIsCallable($tool['callback'] ?? null);
        $tool['callback']('erased-problem@example.com', 1);

        self::assertSame(['The courier did not answer'], $wpdb->get_col("SELECT message FROM {$wpdb->prefix}orillacart_problems"));
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
