<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

/**
 * Live captures (design 71): the next few runs of an extension's handlers, as the shop runs them, recorded for its
 * debugger. A manager arms one for a while and a number of runs; it ends by itself when either is used up.
 */
interface Captures
{
    public const int MOST_RUNS = 20;

    public const int MOST_MINUTES = 60;

    /**
     * @param string|null $what only the handlers whose name starts so (`cart_rule age`, `on order_paid`); every one when null
     */
    public function arm(string $extension, ?string $what, int $runs, \DateTimeImmutable $until): void;

    /** Whether this run of the handler is one to record; it takes one of the runs left when it is. */
    public function take(string $extension, string $what, \DateTimeImmutable $now): bool;

    /** @return array{what: ?string, left: int, until: string}|null the extension's capture while it lasts */
    public function status(string $extension, \DateTimeImmutable $now): ?array;

    public function stop(string $extension): void;
}
