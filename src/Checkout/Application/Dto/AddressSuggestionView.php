<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application\Dto;

/**
 * What a check said of an address the customer entered: the address corrected, or that it was not found.
 */
final readonly class AddressSuggestionView
{
    /**
     * @param string $message for the customer; may be empty for a correction
     */
    public function __construct(
        public string $message,
        public bool $found,
        public ?string $street = null,
        public ?string $city = null,
        public ?string $postcode = null,
        public ?string $state = null,
    ) {}
}
