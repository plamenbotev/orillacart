<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Database;

/*
 * Loaded by autoloading once a test has made sure core wpdb is there (wpdb.php without WordPress), as it
 * extends it.
 */
/** WordPress's database as far as the logger calls it: keeps each query with its values, and writes nothing. */
final class RecordingWpdb extends \wpdb
{
    /** @var list<array{0: string, 1: list<mixed>}> each query with its values */
    public array $written = [];
    /** How many rows the table says it has. */
    public int $rows = 0;

    public function __construct() {} // @phpstan-ignore constructor.missingParentCall

    /**
     * @param bool $suppress
     *
     * @return bool
     */
    public function suppress_errors($suppress = true)
    {
        return false;
    }

    /**
     * @param string $query
     * @param mixed  ...$args
     *
     * @return string
     */
    public function prepare($query, ...$args)
    {
        return (string) json_encode([$query, $args]);
    }

    /**
     * @param string $query
     *
     * @return int
     */
    public function query($query)
    {
        /** @var array{0: string, 1: list<mixed>} $decoded */
        $decoded = json_decode($query, true);
        $this->written[] = $decoded;

        return 1;
    }

    /**
     * @param string|null $query
     * @param int         $x
     * @param int         $y
     *
     * @return non-empty-string
     */
    public function get_var($query = null, $x = 0, $y = 0)
    {
        return (string) $this->rows;
    }
}
