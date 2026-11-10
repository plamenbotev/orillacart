<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

/**
 * Which addresses no email of the shop's is sent to (design 62): the names reserved for examples and tests, and
 * those the site adds (a QA team's own domain).
 */
interface Undeliverable
{
    public function address(string $address): bool;
}
