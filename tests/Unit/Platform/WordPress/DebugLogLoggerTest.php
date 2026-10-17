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

    private function logger(bool $debug): DebugLogLogger
    {
        return new DebugLogLogger($debug, function (string $line): void {
            $this->lines[] = $line;
        });
    }
}
