<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Logging;

use OrillaCart\Shared\Security\LoggedText;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * Writes to PHP's error log, which WordPress points at wp-content/debug.log when WP_DEBUG_LOG is on.
 * Debug and info messages are written only while WP_DEBUG is on.
 *
 * That file may be read by more people than the shop's data, and by anyone where the web server serves it
 * (DebugLogHealth), so a message is written on one line whatever its values hold, without the emails and VAT
 * numbers in it, without what its context keeps under a secret's name, and an exception's trace says where it
 * went, not what was passed: an argument may be a key or a token.
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

    /** Placeholders whose values are not written: a "{key}" alone names a job or a row, and is. */
    private const string SECRET_NAMES = '/(secret|password|passwd|token|api_?key|private|nonce|signature|credential)/i';

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

        $line = \sprintf('[OrillaCart] %s: %s', strtoupper($level), self::interpolate((string) $message, $context));
        $exception = $context['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            $line .= \sprintf("\n%s: %s in %s:%d\n%s", LoggedText::oneLine($exception::class), self::reason($exception), LoggedText::oneLine($exception->getFile()), $exception->getLine(), self::trace($exception));
        }

        if ($this->write !== null) {
            ($this->write)($line);
        } else {
            error_log($line);
        }
    }

    /**
     * The message as it is logged: its {placeholders} filled from the context, on one line, without the emails
     * and VAT numbers in it.
     *
     * @param array<array-key, mixed> $context
     */
    public static function interpolate(string $message, array $context): string
    {
        return LoggedText::withoutPeople(self::filled($message, $context));
    }

    /**
     * The message on one line with its {placeholders} filled from the context, emails and VAT numbers still in
     * it: for telling whom it is about, never for writing. A value kept under a secret's name ("token",
     * "api_key", "password") is not filled in.
     *
     * @param array<array-key, mixed> $context
     */
    public static function filled(string $message, array $context): string
    {
        return LoggedText::oneLine((string) preg_replace_callback(
            '/\{([A-Za-z0-9_.]+)\}/',
            static function (array $match) use ($context): string {
                if (!\array_key_exists($match[1], $context)) {
                    return $match[0];
                }
                $value = $context[$match[1]];

                return match (true) {
                    $value instanceof \Throwable => $value::class,
                    $value === null => 'null',
                    preg_match(self::SECRET_NAMES, $match[1]) === 1 => '••••',
                    \is_scalar($value), $value instanceof \Stringable => (string) $value,
                    default => get_debug_type($value),
                };
            },
            $message,
        ));
    }

    /** What the exception says, as it is logged: on one line, without the emails and VAT numbers in it. */
    public static function reason(\Throwable $exception): string
    {
        return LoggedText::withoutPeople(LoggedText::oneLine($exception->getMessage()));
    }

    /**
     * Where the exception went, a frame a line as PHP writes a trace, but never with the arguments: PHP prints
     * them unless zend.exception_ignore_args is on, and one of them may be a key, a token or a customer's address.
     */
    private static function trace(\Throwable $exception): string
    {
        $lines = [];
        foreach ($exception->getTrace() as $frame) {
            $lines[] = LoggedText::oneLine(\sprintf(
                '#%d %s: %s%s%s()',
                \count($lines),
                isset($frame['file']) ? $frame['file'] . '(' . ($frame['line'] ?? 0) . ')' : '[internal function]',
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'],
            ));
        }
        $lines[] = '#' . \count($lines) . ' {main}';

        return implode("\n", $lines);
    }
}
