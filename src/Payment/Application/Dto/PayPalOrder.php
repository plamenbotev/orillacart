<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

/**
 * A PayPal order as created: its id, and the page where the customer approves it.
 */
final readonly class PayPalOrder
{
    public function __construct(
        public string $id,
        public string $approveUrl,
    ) {}
}
