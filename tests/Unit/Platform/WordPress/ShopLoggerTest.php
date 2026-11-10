<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\WordPress;

use OrillaCart\Platform\WordPress\Logging\DebugLogLogger;
use OrillaCart\Platform\WordPress\Logging\ShopLogger;
use OrillaCart\Shared\Security\LoggedText;
use OrillaCart\Shared\Security\Signatures;
use OrillaCart\Tests\Fixtures\Database\RecordingWpdb;
use PHPUnit\Framework\TestCase;

/**
 * What the shop's logger keeps for the shop manager: one row a problem whatever a request put into it, without
 * the emails it was about, and never more rows than a site may have.
 */
final class ShopLoggerTest extends TestCase
{
    private mixed $wpdbBefore = null;
    private RecordingWpdb $database;

    protected function setUp(): void
    {
        // Unit tests run without WordPress: core wpdb's shape, and on it what the logger calls.
        if (!class_exists(\wpdb::class, false)) {
            require_once \dirname(__DIR__, 3) . '/Fixtures/Database/wpdb.php';
        }
        $this->wpdbBefore = $GLOBALS['wpdb'] ?? null;
        $this->database = new RecordingWpdb();
        $GLOBALS['wpdb'] = $this->database;
    }

    protected function tearDown(): void
    {
        ShopLogger::flushAll();
        $GLOBALS['wpdb'] = $this->wpdbBefore;
    }

    public function testAMessageWithAnotherValueEachTimeIsOneProblemThatShowsTheLatest(): void
    {
        $logger = $this->logger();
        $logger->warning('VIES could not be reached: {message}', ['message' => 'timeout after 5001 ms']);
        $logger->warning('VIES could not be reached: {message}', ['message' => 'timeout after 5002 ms']);
        $logger->error('PayPal refused /orders/17 (debug id a1b2)');
        $logger->error('PayPal refused /orders/2051 (debug id a1b2)');
        $logger->error('A refund went wrong', ['exception' => new \RuntimeException('PayPal said no to 12')]);
        $logger->error('A refund went wrong', ['exception' => new \RuntimeException('PayPal said no to 13')]);
        $logger->error('A refund went wrong', ['exception' => new \LogicException('Not refundable')]);
        $logger->notice('Only for debug.log');
        $logger->flush();

        self::assertSame(
            [
                ['warning', 'VIES could not be reached: timeout after 5002 ms', 2],
                ['error', 'PayPal refused /orders/2051 (debug id a1b2)', 2],
                ['error', 'A refund went wrong (RuntimeException: PayPal said no to 13)', 2],
                ['error', 'A refund went wrong (LogicException: Not refundable)', 1],
            ],
            array_map(static fn(array $insert): array => [$insert[1][2], $insert[1][3], $insert[1][5]], $this->inserts()),
        );
        self::assertSame(ShopLogger::fingerprint('warning', 'VIES could not be reached: {message}'), $this->inserts()[0][1][1]);
        self::assertStringContainsString('message = VALUES(message)', $this->inserts()[0][0], 'a row seen again shows what happened last');
    }

    public function testAKeptProblemHasNoEmailOrVatNumberButAMarkOfWhomItWasAbout(): void
    {
        $signatures = new Signatures('the site key');
        $logger = $this->logger($signatures);
        $logger->warning('The email {template} to {to} did not go: {reason}', ['template' => 'order_confirmation', 'to' => 'Jane@Example.com', 'reason' => 'SMTP: <jane@example.com> rejected']);
        $logger->warning('VIES did not check {number}: {answer}', ['number' => 'DE123456789', 'answer' => 'MS_UNAVAILABLE']);
        $logger->error('A job failed', ['exception' => new \RuntimeException("No mailbox joe@example.org\nmade up line")]);
        $logger->flush();

        $inserts = $this->inserts();
        self::assertSame('The email order_confirmation to [email] did not go: SMTP: <[email]> rejected', $inserts[0][1][3]);
        self::assertSame(LoggedText::aboutMark($signatures, 'jane@example.com'), $inserts[0][1][4]);
        self::assertSame('VIES did not check [VAT number]: MS_UNAVAILABLE', $inserts[1][1][3]);
        self::assertSame('', $inserts[1][1][4]);
        self::assertSame('A job failed (RuntimeException: No mailbox [email] made up line)', $inserts[2][1][3]);
        self::assertSame(LoggedText::aboutMark($signatures, 'joe@example.org'), $inserts[2][1][4]);
    }

    public function testASiteKeepsNoMoreProblemsThanItMay(): void
    {
        $logger = $this->logger();
        $logger->warning('One more');
        $this->database->rows = ShopLogger::MAX_ROWS + 7;
        $logger->flush();

        $last = array_last($this->database->written);
        self::assertNotNull($last);
        self::assertSame('DELETE FROM %i ORDER BY last_at ASC, id ASC LIMIT %d', $last[0]);
        self::assertSame(['wp_3_orillacart_problems', 7], $last[1]);
    }

    public function testATableWithinItsLimitLosesNothing(): void
    {
        $logger = $this->logger();
        $logger->warning('One more');
        $this->database->rows = ShopLogger::MAX_ROWS;
        $logger->flush();

        self::assertCount(1, $this->database->written);
    }

    /** @return list<array{0: string, 1: list<mixed>}> */
    private function inserts(): array
    {
        return array_values(array_filter($this->database->written, static fn(array $query): bool => str_starts_with($query[0], 'INSERT ')));
    }

    private function logger(?Signatures $signatures = null): ShopLogger
    {
        return new ShopLogger(new DebugLogLogger(false, static function (): void {}), $signatures);
    }
}
