<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Domain;

interface AgentCheckoutRepository
{
    public function find(string $id): ?AgentCheckout;

    /** The open session of a cart, if an agent made it. */
    public function forCart(int $cartId): ?AgentCheckout;

    public function save(AgentCheckout $checkout): void;

    /** @return int how many sessions over for a day were removed */
    public function forgetOlderThan(\DateTimeImmutable $before): int;
}
