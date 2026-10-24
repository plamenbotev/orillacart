<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Infrastructure\Events;

use OrillaCart\Ordering\Domain\Event\OrderRefunded;
use OrillaCart\Returns\Application\Returns;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** The order recorded a refund: a return whose refund the provider was sending is refunded now. */
#[AsEventListener]
final readonly class SettleReturnRefunds
{
    public function __construct(private Returns $returns) {}

    public function __invoke(OrderRefunded $event): void
    {
        $this->returns->settle($event->orderId);
    }
}
