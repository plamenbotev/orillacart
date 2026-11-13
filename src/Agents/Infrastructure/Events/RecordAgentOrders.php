<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Infrastructure\Events;

use OrillaCart\Agents\Application\AgentCheckouts;
use OrillaCart\Cart\Domain\Event\CartClaimed;
use OrillaCart\Cart\Domain\Event\CartOrdered;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The order a buyer placed from an agent's cart completes the agent's checkout session; a buyer logging in with the
 * cart takes the session along to the cart it becomes.
 */
final readonly class RecordAgentOrders
{
    public function __construct(private AgentCheckouts $checkouts) {}

    #[AsEventListener]
    public function ordered(CartOrdered $event): void
    {
        $this->checkouts->ordered($event->cartId, $event->orderId);
    }

    #[AsEventListener]
    public function claimed(CartClaimed $event): void
    {
        $this->checkouts->claimed($event->fromCartId, $event->intoCartId);
    }
}
