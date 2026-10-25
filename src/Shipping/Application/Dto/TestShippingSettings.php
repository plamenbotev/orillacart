<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A shipping method's settings to try, as the form has them: a secret left empty is the one the carrier saved.
 */
final readonly class TestShippingSettings
{
    /** @param array<string, string|bool> $settings by key */
    public function __construct(
        public array $settings = [],
        /** The carrier whose saved secrets fill the empty ones; null for a carrier not saved yet. */
        #[Assert\Positive]
        public ?int $carrierId = null,
    ) {}
}
