<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * The shop's logger: everything goes to PHP's error log as before (DebugLogLogger), and warnings and worse are
 * also kept where the shop manager reads them (Shop → Status, Site Health; LoggedProblem), the same message once
 * with how often it happened. They are written when the request ends, outside any transaction that might roll
 * them back with the work that failed, straight through WordPress's database (never Doctrine's, whose entity
 * manager a failure may have closed).
 */
final class ShopLogger extends AbstractLogger
{
    private const array KEPT = [LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY];
    /** A long-running worker writes what it has once this many wait. */
    private const int FLUSH_AT = 50;
    private const int MAX_MESSAGE = 2000;

    /** @var array<string, array{level: string, message: string, count: int, at: string}> by fingerprint */
    private array $waiting = [];
    private bool $registered = false;

    public function __construct(private readonly DebugLogLogger $debugLog) {}

    /**
     * @param array<array-key, mixed> $context
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->debugLog->log($level, $message, $context);
        if (!\is_string($level) || !\in_array($level, self::KEPT, true)) {
            return;
        }
        $text = DebugLogLogger::interpolate((string) $message, $context);
        $exception = $context['exception'] ?? null;
        if ($exception instanceof \Throwable && !str_contains($text, $exception->getMessage())) {
            $text .= ' (' . $exception::class . ': ' . $exception->getMessage() . ')';
        }
        $text = mb_substr($text, 0, self::MAX_MESSAGE);
        $fingerprint = sha1($level . "\n" . $text);
        $this->waiting[$fingerprint] = ['level' => $level, 'message' => $text, 'count' => ($this->waiting[$fingerprint]['count'] ?? 0) + 1, 'at' => gmdate('Y-m-d H:i:s')];
        if (!$this->registered) {
            $this->registered = true;
            register_shutdown_function($this->flush(...));
        }
        if (\count($this->waiting) >= self::FLUSH_AT) {
            $this->flush();
        }
    }

    /** Writes what waits; a database that is not there (or a table not made yet) loses it, as debug.log has it. */
    public function flush(): void
    {
        global $wpdb;
        if ($this->waiting === [] || !$wpdb instanceof \wpdb) {
            return;
        }
        $waiting = $this->waiting;
        $this->waiting = [];
        $table = $wpdb->prefix . 'orillacart_problems';
        $suppressed = $wpdb->suppress_errors(true);
        foreach ($waiting as $fingerprint => $problem) {
            $wpdb->query((string) $wpdb->prepare(
                'INSERT INTO %i (fingerprint, level, message, count, first_at, last_at) VALUES (%s, %s, %s, %d, %s, %s) ON DUPLICATE KEY UPDATE count = count + VALUES(count), last_at = VALUES(last_at)',
                $table,
                $fingerprint,
                $problem['level'],
                $problem['message'],
                $problem['count'],
                $problem['at'],
                $problem['at'],
            ));
        }
        $wpdb->suppress_errors($suppressed);
    }
}
