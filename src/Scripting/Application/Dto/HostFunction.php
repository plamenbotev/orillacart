<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application\Dto;

/**
 * One function the host offers a script: its dotted name (`http.post`, `objects.*.query` with `*` for a segment
 * the script names, `money` for a builtin), how many arguments it takes, what permission it needs, and what it
 * counts against. The callable gets the run's context, the named segments, then the script's arguments. A function
 * that reaches outside the shop (mail, an order placed) says what it does in an extension's own tests instead:
 * tests run with whatever the extension declares, saved or not, so they must never send or place for real.
 */
final readonly class HostFunction
{
    public const string CALL = 'call';
    public const string HTTP = 'http';
    public const string QUERY = 'query';
    public const string EMAIL = 'email';

    /**
     * @param \Closure                          $callable takes the run's Context, the named segments, then the script's arguments, all mixed
     * @param string|null                       $needs    a permission such as `orders:read`, `http`; null for none
     * @param self::CALL|self::HTTP|self::QUERY|self::EMAIL $counts
     * @param \Closure|null                     $inTests  takes what $callable takes, and stands in for it in tests
     */
    public function __construct(
        public string $name,
        public \Closure $callable,
        public int $leastArguments,
        public ?int $mostArguments,
        public string $summary,
        public ?string $needs = null,
        public string $counts = self::CALL,
        public ?\Closure $inTests = null,
    ) {}
}
