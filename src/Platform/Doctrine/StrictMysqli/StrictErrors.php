<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine\StrictMysqli;

/**
 * Runs a mysqli call with errors reported as exceptions, then restores the previous report mode.
 *
 * DBAL's mysqli driver only notices failed SQL through mysqli_sql_exception, but WordPress switches the
 * process-wide report mode off for wpdb, which checks return values instead. Without this, a failed
 * Doctrine query looked like a success. The mode is changed only for the length of Doctrine's own call,
 * so wpdb keeps working as WordPress expects.
 */
final class StrictErrors
{
    /**
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    public static function run(\Closure $call): mixed
    {
        $previous = new \mysqli_driver()->report_mode;
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            return $call();
        } finally {
            mysqli_report($previous);
        }
    }
}
