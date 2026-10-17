<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * Writes to PHP's error log, which WordPress points at wp-content/debug.log when WP_DEBUG_LOG is on.
 * Debug and info messages are written only while WP_DEBUG is on.
 */
final class DebugLogLogger extends AbstractLogger
{
    private const array SEVERITY = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    private readonly int $threshold;

    /**
     * @param \Closure(string): void|null $write where lines go; error_log() by default
     */
    public function __construct(?bool $debug = null, private readonly ?\Closure $write = null)
    {
        $debug ??= \defined('WP_DEBUG') && WP_DEBUG;
        $this->threshold = self::SEVERITY[$debug ? LogLevel::DEBUG : LogLevel::NOTICE];
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        if (!\is_string($level) || !isset(self::SEVERITY[$level])) {
            throw new InvalidArgumentException(\sprintf('Unknown log level %s.', \is_string($level) ? '"' . $level . '"' : get_debug_type($level)));
        }
        if (self::SEVERITY[$level] < $this->threshold) {
            return;
        }

        $line = \sprintf('[OrillaCart] %s: %s', strtoupper($level), $this->interpolate((string) $message, $context));
        $exception = $context['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            $line .= \sprintf("\n%s: %s in %s:%d\n%s", $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine(), $exception->getTraceAsString());
        }

        if ($this->write !== null) {
            ($this->write)($line);
        } else {
            error_log($line);
        }
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        return (string) preg_replace_callback(
            '/\{([A-Za-z0-9_.]+)\}/',
            static function (array $match) use ($context): string {
                if (!\array_key_exists($match[1], $context)) {
                    return $match[0];
                }
                $value = $context[$match[1]];

                return match (true) {
                    $value instanceof \Throwable => $value::class,
                    \is_scalar($value), $value instanceof \Stringable => (string) $value,
                    $value === null => 'null',
                    default => get_debug_type($value),
                };
            },
            $message,
        );
    }
}
