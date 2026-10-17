<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\Address;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A postal address as the customer types it at checkout, and as orders show it.
 */
final readonly class AddressData
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: Address::MAX_LENGTH)]
        public string $firstName,
        #[Assert\NotBlank]
        #[Assert\Length(max: Address::MAX_LENGTH)]
        public string $lastName,
        #[Assert\NotBlank]
        #[Assert\Length(max: Address::MAX_LENGTH)]
        public string $street,
        #[Assert\NotBlank]
        #[Assert\Length(max: Address::MAX_LENGTH)]
        public string $city,
        #[Assert\NotBlank]
        #[Assert\Length(max: 20)]
        public string $postcode,
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/^[A-Z]{2}$/', message: 'Choose a country.')]
        public string $country,
        #[Assert\NotBlank]
        #[Assert\Length(max: 40)]
        #[Assert\Regex(pattern: '/^[0-9 +().\/-]+$/', message: 'Use digits, spaces and + ( ) - . / only.')]
        public string $phone,
        #[Assert\Regex(pattern: '/^[A-Z0-9]{1,3}$/', message: 'Choose a state.')]
        public ?string $state = null,
    ) {}

    public static function fromAddress(Address $address): self
    {
        return new self($address->firstName(), $address->lastName(), $address->street(), $address->city(), $address->postcode(), $address->country(), $address->phone(), $address->state());
    }

    public function toAddress(): Address
    {
        return Address::of($this->firstName, $this->lastName, $this->street, $this->city, $this->postcode, $this->country, $this->state, $this->phone);
    }
}
