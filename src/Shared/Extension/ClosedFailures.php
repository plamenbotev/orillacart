<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Extension;

/**
 * Add-ons the shop cannot do without (FailsClosed) that failed, kept past the request: while one fails, shoppers
 * are refused (no cart can be ordered, no address passes), which the shop manager must hear of at once, not find
 * among the log's lines. The Status screen and Home show those of the last hour.
 */
interface ClosedFailures
{
    /**
     * @param string $id   the add-on's part that failed, as "cart_rule:age-check"
     * @param string $what for the shop manager: what failed and what it stops
     */
    public function failed(string $id, string $what): void;

    /**
     * @return list<array{id: string, what: string, at: \DateTimeImmutable}> the latest failure of each, newest first
     */
    public function since(\DateTimeImmutable $since): array;
}
