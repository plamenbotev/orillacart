<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * The shop's logger: everything goes to PHP's error log as before (DebugLogLogger), and warnings and worse are
 * also kept where the shop manager reads them (Shop → Status, Site Health; LoggedProblem), the same message once
 * with how often it happened. They are written when the request ends, outside any transaction that might roll
 * them back with the work that failed (a worker's after each batch, and once the first has waited a minute), straight through WordPress's database (never Doctrine's, whose entity
 * manager a failure may have closed). Each goes to the site it happened on: a process that goes through a
 * network's sites logs on several, each site's container with a logger of its own.
 */
final class ShopLogger extends AbstractLogger
{
    private const array KEPT = [LogLevel::WARNING, LogLevel::ERROR, LogLevel::CRITICAL, LogLevel::ALERT, LogLevel::EMERGENCY];
    /** A long-running worker writes what it has once this many wait, or once the first has waited this long. */
    private const int FLUSH_AT = 50;
    private const int FLUSH_AFTER_SECONDS = 60;
    private const int MAX_MESSAGE = 2000;

    /** @var array<string, array{table: string, level: string, message: string, count: int, first: string, last: string}> by table and fingerprint */
    private array $waiting = [];
    /** When the first of those waiting came. */
    private ?int $since = null;

    /**
     * The loggers with something waiting, flushed together when the process ends and after each batch a worker
     * runs: a network worker is done with a site's container long before then. A logger holds nothing of it.
     *
     * @var array<int, self> by object id
     */
    private static array $loggers = [];
    private static bool $hooked = false;

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
        global $wpdb;
        // The site's table as it is now, while a process going through a network's sites is on this one.
        $table = ($wpdb instanceof \wpdb ? $wpdb->prefix : '') . 'orillacart_problems';
        $key = $table . "\n" . sha1($level . "\n" . $text);
        $now = gmdate('Y-m-d H:i:s');
        $this->waiting[$key] = [
            'table' => $table,
            'level' => $level,
            'message' => $text,
            'count' => ($this->waiting[$key]['count'] ?? 0) + 1,
            'first' => $this->waiting[$key]['first'] ?? $now,
            'last' => $now,
        ];
        $this->since ??= time();
        self::$loggers[spl_object_id($this)] = $this;
        if (!self::$hooked) {
            self::$hooked = true;
            register_shutdown_function(self::flushAll(...));
            // And after each batch a worker runs.
            if (\function_exists('add_action')) {
                add_action('orillacart_jobs_worked', self::flushAll(...));
            }
        }
        if (\count($this->waiting) >= self::FLUSH_AT || time() - $this->since >= self::FLUSH_AFTER_SECONDS) {
            $this->flush();
        }
    }

    public static function flushAll(): void
    {
        $loggers = self::$loggers;
        self::$loggers = [];
        foreach ($loggers as $logger) {
            $logger->flush();
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
        $this->since = null;
        unset(self::$loggers[spl_object_id($this)]);
        $suppressed = $wpdb->suppress_errors(true);
        foreach ($waiting as $problem) {
            $wpdb->query((string) $wpdb->prepare(
                'INSERT INTO %i (fingerprint, level, message, count, first_at, last_at) VALUES (%s, %s, %s, %d, %s, %s) ON DUPLICATE KEY UPDATE count = count + VALUES(count), last_at = VALUES(last_at)',
                $problem['table'],
                sha1($problem['level'] . "\n" . $problem['message']),
                $problem['level'],
                $problem['message'],
                $problem['count'],
                $problem['first'],
                $problem['last'],
            ));
        }
        $wpdb->suppress_errors($suppressed);
    }
}
