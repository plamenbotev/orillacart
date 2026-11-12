<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application\Dto;

/**
 * One function the host offers a script: its dotted name (`http.post`, `objects.*.query` with `*` for a segment
 * the script names, `money` for a builtin), how many arguments it takes, what permission it needs, and what it
 * counts against. The callable gets the run's context, the named segments, then the script's arguments. A function
 * that reaches outside the shop (mail, an order placed) says what it does in an extension's own tests instead:
 * tests answer for whatever the extension declares, granted or not, so they must never send or place for real.
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
     * @param bool|null                         $writes   whether it changes something or reaches outside the shop
     *                                                    (a request that may only read refuses it); null, not
     *                                                    declared, counts as writing
     * @param \Closure|null                     $madeInTests takes what $callable takes, and stands in for it in a
     *                                                    test whose extension does not declare $needs: a function
     *                                                    that makes what the test needs (a customer, a product, an
     *                                                    order), undone with the test. Called in a test's body it
     *                                                    needs no permission; anywhere else it does.
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
        public ?bool $writes = null,
        public ?\Closure $madeInTests = null,
    ) {}

    /**
     * Functions that only work a value out (a namespace of builtins), declared as reading where they say nothing.
     *
     * @param list<self> $functions
     *
     * @return list<self>
     */
    public static function reading(array $functions): array
    {
        return array_map(static fn(self $f): self => $f->writes !== null ? $f : new self($f->name, $f->callable, $f->leastArguments, $f->mostArguments, $f->summary, $f->needs, $f->counts, $f->inTests, false, $f->madeInTests), $functions);
    }
}
