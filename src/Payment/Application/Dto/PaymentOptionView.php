<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

/**
 * A payment method checkout offers: what the customer picks and reads before ordering.
 */
final readonly class PaymentOptionView
{
    public function __construct(
        public string $gateway,
        public string $name,
        public string $instructions,
    ) {}
}
