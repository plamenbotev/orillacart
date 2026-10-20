<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application\Dto;

/**
 * Command: give the money back for the items that came back.
 */
final readonly class RefundReturn
{
    public function __construct(
        /** The shop gave the money back itself, not through the payment provider. */
        public bool $outside = false,
    ) {}
}
