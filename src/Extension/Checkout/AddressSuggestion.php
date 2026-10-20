<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Checkout;

/**
 * What a check says of an address: here is the address corrected, or it could not find the address at all.
 */
final readonly class AddressSuggestion
{
    /**
     * @param string $message for the customer, in their language: "We could not find this street in Sofia."
     */
    private function __construct(
        public ?string $street,
        public ?string $city,
        public ?string $postcode,
        public ?string $state,
        public string $message,
    ) {}

    /**
     * The address as the register has it. The country stays the customer's; a part left null stays as entered.
     */
    public static function corrected(?string $street = null, ?string $city = null, ?string $postcode = null, ?string $state = null, string $message = ''): self
    {
        return new self($street, $city, $postcode, $state, $message);
    }

    /** The register has no such address; the customer may still keep it. */
    public static function notFound(string $message): self
    {
        return new self(null, null, null, null, $message);
    }

    public function isCorrection(): bool
    {
        return $this->street !== null || $this->city !== null || $this->postcode !== null || $this->state !== null;
    }
}
