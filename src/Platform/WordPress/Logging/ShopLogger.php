<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Logging;

use OrillaCart\Shared\Security\LoggedText;
use OrillaCart\Shared\Security\Signatures;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * The shop's logger: everything goes to PHP's error log as before (DebugLogLogger), and warnings and worse are
 * also kept where the shop manager reads them (Shop → Status, Site Health; LoggedProblem), the same message once
 * with how often it happened. The same message is the same text as the code wrote it, before its values were
 * filled in and whatever its numbers: a courier that fails for a hundred orders is one row that shows the latest,
 * not a hundred, and a site keeps no more than MAX_ROWS of them, the latest seen. Emails and VAT numbers are left
 * out of what is kept (LoggedText); a row keeps instead a mark made of each email it was about, a signature only
 * this site can make, so that erasing a person finds the rows about them. They are written when the request ends, outside any transaction that might roll
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
    /** How many problems a site keeps: past it the ones seen longest ago go. */
    public const int MAX_ROWS = 500;
    /** How many people one row is marked as being about. */
    private const int MAX_ABOUT = 12;

    /** @var array<string, array{table: string, fingerprint: string, level: string, message: string, about: string, count: int, first: string, last: string}> by table and fingerprint */
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

    public function __construct(private readonly DebugLogLogger $debugLog, private readonly ?Signatures $signatures = null) {}

    /**
     * @param array<array-key, mixed> $context
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->debugLog->log($level, $message, $context);
        if (!\is_string($level) || !\in_array($level, self::KEPT, true)) {
            return;
        }
        $filled = DebugLogLogger::filled((string) $message, $context);
        $text = LoggedText::withoutPeople($filled);
        $exception = $context['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            $filled .= ' ' . $exception->getMessage();
            $reason = DebugLogLogger::reason($exception);
            if (!str_contains($text, $reason)) {
                $text .= ' (' . LoggedText::oneLine($exception::class) . ': ' . $reason . ')';
            }
        }
        $text = mb_substr($text, 0, self::MAX_MESSAGE);
        global $wpdb;
        // The site's table as it is now, while a process going through a network's sites is on this one.
        $table = ($wpdb instanceof \wpdb ? $wpdb->prefix : '') . 'orillacart_problems';
        $fingerprint = self::fingerprint($level, (string) $message, $exception instanceof \Throwable ? $exception : null);
        $key = $table . "\n" . $fingerprint;
        $now = gmdate('Y-m-d H:i:s');
        $this->waiting[$key] = [
            'table' => $table,
            'fingerprint' => $fingerprint,
            'level' => $level,
            // What happened last is what the row shows, and whom it is about.
            'message' => $text,
            'about' => $this->about($filled),
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

    /**
     * One problem's name: its level, the message as the code wrote it (placeholders unfilled, every number the
     * same), and the kind of exception it came with. What a request put into it does not make it another problem.
     */
    public static function fingerprint(string $level, string $template, ?\Throwable $exception = null): string
    {
        return sha1($level . "\n" . preg_replace('/\d+/', '0', $template) . "\n" . ($exception === null ? '' : $exception::class));
    }

    /** The marks of the people the text mentions, by their emails. */
    private function about(string $text): string
    {
        $marks = [];
        foreach (\array_slice(LoggedText::emails($text), 0, self::MAX_ABOUT) as $email) {
            $marks[] = LoggedText::aboutMark($this->signatures, $email);
        }

        return implode(' ', array_filter($marks, static fn(?string $mark): bool => $mark !== null));
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
        $tables = [];
        foreach ($waiting as $problem) {
            $tables[$problem['table']] = true;
            $wpdb->query((string) $wpdb->prepare(
                'INSERT INTO %i (fingerprint, level, message, about, count, first_at, last_at) VALUES (%s, %s, %s, %s, %d, %s, %s) ON DUPLICATE KEY UPDATE count = count + VALUES(count), last_at = VALUES(last_at), message = VALUES(message), about = VALUES(about)',
                $problem['table'],
                $problem['fingerprint'],
                $problem['level'],
                $problem['message'],
                $problem['about'],
                $problem['count'],
                $problem['first'],
                $problem['last'],
            ));
        }
        // No more than MAX_ROWS a site: whatever makes a new problem of every request cannot fill the table.
        foreach (array_keys($tables) as $table) {
            $rows = (int) $wpdb->get_var((string) $wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
            if ($rows > self::MAX_ROWS) {
                $wpdb->query((string) $wpdb->prepare('DELETE FROM %i ORDER BY last_at ASC, id ASC LIMIT %d', $table, $rows - self::MAX_ROWS));
            }
        }
        $wpdb->suppress_errors($suppressed);
    }
}
