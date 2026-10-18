<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\Doctrine;

/**
 * Named parameters for SQL put together from pieces: each value gets its own name, so pieces never clash.
 */
final class SqlParams
{
    /** @var array<string, string|int> */
    private array $values = [];

    /** The placeholder of a new parameter: ":p3". */
    public function add(string|int $value): string
    {
        $name = 'p' . \count($this->values);
        $this->values[$name] = $value;

        return ':' . $name;
    }

    /**
     * @param list<string|int> $values
     *
     * @return string placeholders for an IN list: ":p4, :p5"
     */
    public function list(array $values): string
    {
        return implode(', ', array_map($this->add(...), $values));
    }

    /** @return array<string, string|int> */
    public function values(): array
    {
        return $this->values;
    }
}
