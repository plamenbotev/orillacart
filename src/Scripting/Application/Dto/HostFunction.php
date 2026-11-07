<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application\Dto;

/**
 * One function the host offers a script: its dotted name (`http.post`, `objects.*.query` with `*` for a segment
 * the script names, `money` for a builtin), how many arguments it takes, what permission it needs, and what it
 * counts against. The callable gets the named segments first, then the script's arguments.
 */
final readonly class HostFunction
{
    public const string CALL = 'call';
    public const string HTTP = 'http';
    public const string QUERY = 'query';

    /**
     * @param \Closure                          $callable takes the named segments, then the script's arguments, all mixed
     * @param string|null                       $needs    a permission such as `orders:read`, `http`; null for none
     * @param self::CALL|self::HTTP|self::QUERY $counts
     */
    public function __construct(
        public string $name,
        public \Closure $callable,
        public int $leastArguments,
        public ?int $mostArguments,
        public string $summary,
        public ?string $needs = null,
        public string $counts = self::CALL,
    ) {}
}
