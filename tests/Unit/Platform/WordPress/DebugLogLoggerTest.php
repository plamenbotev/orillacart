<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform\WordPress;

use OrillaCart\Platform\WordPress\Logging\DebugLogLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;

final class DebugLogLoggerTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    public function testPlaceholdersAreFilledFromTheContext(): void
    {
        $this->logger(debug: false)->error('Order {id} failed for {user}: {missing}', ['id' => 42, 'user' => null]);

        self::assertSame(['[OrillaCart] ERROR: Order 42 failed for null: {missing}'], $this->lines);
    }

    public function testExceptionsAreWrittenWithTheirLocation(): void
    {
        $exception = new \RuntimeException('boom');
        $this->logger(debug: false)->critical('Unhandled {exception}', ['exception' => $exception]);

        self::assertStringStartsWith("[OrillaCart] CRITICAL: Unhandled RuntimeException\nRuntimeException: boom in " . __FILE__, $this->lines[0]);
    }

    public function testDebugMessagesNeedWpDebug(): void
    {
        $this->logger(debug: false)->info('quiet');
        $this->logger(debug: false)->notice('loud');
        $this->logger(debug: true)->debug('verbose');

        self::assertSame(['[OrillaCart] NOTICE: loud', '[OrillaCart] DEBUG: verbose'], $this->lines);
    }

    public function testUnknownLevelsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->logger(debug: true)->log('verbose', 'x');
    }

    public function testAValueWithLineBreaksCannotStartALineOfItsOwn(): void
    {
        $this->logger(debug: false)->warning('The courier answered {answer}', ['answer' => "no\n[OrillaCart] CRITICAL: Order 1 was refunded\r\n\x1B[2J"]);
        $this->logger(debug: false)->error('Failed', ['exception' => new \RuntimeException("first\n[OrillaCart] ERROR: made up")]);

        self::assertSame('[OrillaCart] WARNING: The courier answered no [OrillaCart] CRITICAL: Order 1 was refunded [2J', $this->lines[0]);
        self::assertStringStartsWith("[OrillaCart] ERROR: Failed\nRuntimeException: first [OrillaCart] ERROR: made up in " . __FILE__, $this->lines[1]);
        self::assertSame(0, preg_match('/^\[OrillaCart\] ERROR: made up/m', $this->lines[1]));
    }

    public function testATraceSaysWhereItWentAndNotWhatWasPassed(): void
    {
        // As a server set up for development has it: PHP keeps a trace's arguments, and prints them in its own.
        $ignored = ini_set('zend.exception_ignore_args', '0');
        try {
            $exception = self::failWith('sk_live_a-secret-key', ['token' => 'a-secret-token']);
        } finally {
            if ($ignored !== false) {
                ini_set('zend.exception_ignore_args', $ignored);
            }
        }
        self::assertSame('sk_live_a-secret-key', $exception->getTrace()[0]['args'][0] ?? null, 'PHP kept what was passed');

        $this->logger(debug: false)->error('The gateway failed', ['exception' => $exception]);

        self::assertStringNotContainsString('sk_live_a-secret-key', $this->lines[0]);
        self::assertStringNotContainsString('a-secret-token', $this->lines[0]);
        self::assertMatchesRegularExpression('/^#0 ' . preg_quote(__FILE__, '/') . '\(\d+\): ' . preg_quote(self::class, '/') . '::failWith\(\)$/m', $this->lines[0]);
        self::assertMatchesRegularExpression('/^#\d+ \{main\}$/m', $this->lines[0]);
    }

    public function testEmailsVatNumbersAndValuesNamedAsSecretsAreLeftOut(): void
    {
        $this->logger(debug: false)->warning(
            'The email {template} to {to} did not go ({reason}); VIES did not check {number}; sent with {api_key} and {token}',
            ['template' => 'order_confirmation', 'to' => 'jane@example.com', 'reason' => 'SMTP: <jane@example.com> rejected', 'number' => 'DE123456789', 'api_key' => 'sk_live_1', 'token' => 'abc'],
        );
        $this->logger(debug: false)->error('Failed', ['exception' => new \RuntimeException('No mailbox joe@example.org')]);

        self::assertSame('[OrillaCart] WARNING: The email order_confirmation to [email] did not go (SMTP: <[email]> rejected); VIES did not check [VAT number]; sent with •••• and ••••', $this->lines[0]);
        self::assertStringContainsString('RuntimeException: No mailbox [email] in ', $this->lines[1]);
        self::assertStringNotContainsString('joe@example.org', $this->lines[1]);
    }

    public function testAJobsKeyIsWrittenThoughASecretsIsNot(): void
    {
        $this->logger(debug: false)->error('Job {type} {key} failed with {client_secret}', ['type' => 'mail', 'key' => 'order-12', 'client_secret' => 'shh']);

        self::assertSame('[OrillaCart] ERROR: Job mail order-12 failed with ••••', $this->lines[0]);
    }

    /**
     * @param array<string, string> $more
     */
    private static function failWith(string $key, array $more): \RuntimeException
    {
        return new \RuntimeException('The gateway said no');
    }

    private function logger(bool $debug): DebugLogLogger
    {
        return new DebugLogLogger($debug, function (string $line): void {
            $this->lines[] = $line;
        });
    }
}
