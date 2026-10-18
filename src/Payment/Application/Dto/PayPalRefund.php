<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

/**
 * A refund PayPal took. Status is PayPal's: COMPLETED, PENDING, CANCELLED, FAILED.
 */
final readonly class PayPalRefund
{
    public function __construct(
        public string $id,
        public string $status,
    ) {}
}
