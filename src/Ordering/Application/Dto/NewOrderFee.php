<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

/**
 * A fee an order pays besides its items and shipping, as checkout priced it: before tax, or including it when the
 * order's prices do.
 */
final readonly class NewOrderFee
{
    public function __construct(
        public string $label,
        public string $amount,
    ) {}
}
