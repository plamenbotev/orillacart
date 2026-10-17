<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

/**
 * The money movement of an approved PayPal order. Status is PayPal's: COMPLETED, PENDING, DECLINED, FAILED...
 */
final readonly class PayPalCapture
{
    public function __construct(
        public string $id,
        public string $status,
        public string $amount,
        public string $currency,
        /** The PayPal order the capture belongs to. */
        public string $orderId,
    ) {}
}
