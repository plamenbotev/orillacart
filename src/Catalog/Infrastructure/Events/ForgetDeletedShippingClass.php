<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\Events;

use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Shipping\Domain\Event\ShippingClassDeleted;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final readonly class ForgetDeletedShippingClass
{
    public function __construct(private ProductRepository $products) {}

    public function __invoke(ShippingClassDeleted $event): void
    {
        $this->products->forgetShippingClass($event->id);
    }
}
