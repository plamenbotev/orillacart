<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

/**
 * What the address checks said of each address on the checkout form; null where they had nothing to say.
 */
final readonly class AddressSuggestionsView
{
    public function __construct(
        public ?AddressSuggestionView $billing,
        public ?AddressSuggestionView $shippingAddress,
    ) {}
}
