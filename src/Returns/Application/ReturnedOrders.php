<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application;

use OrillaCart\Ordering\Application\OrderMark;
use OrillaCart\Returns\Domain\ReturnRepository;

/**
 * The orders a customer asked to return items of, for the shop's order filters.
 */
final readonly class ReturnedOrders implements OrderMark
{
    public function __construct(private ReturnRepository $returns) {}

    public function key(): string
    {
        return 'return';
    }

    public function label(): string
    {
        return 'A return';
    }

    public function orderIds(): array
    {
        return $this->returns->orderIds();
    }
}
