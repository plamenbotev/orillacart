<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Payment;

/**
 * The provider took the refund: its id for it, and whether the money is still on its way.
 */
final readonly class RefundResult
{
    private function __construct(
        public string $reference,
        public bool $pending,
    ) {}

    public static function done(string $reference): self
    {
        return new self($reference, false);
    }

    /** Accepted, and the provider finishes it later, as for an eCheck. */
    public static function pending(string $reference): self
    {
        return new self($reference, true);
    }
}
