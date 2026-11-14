<?php

declare(strict_types=1);

namespace OrillaCart\Geography\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A postal address as the customer types it at checkout, as accounts keep it and as orders show it.
 */
final readonly class AddressData
{
    public const int MAX_LENGTH = 200;

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: self::MAX_LENGTH)]
        public string $firstName,
        #[Assert\NotBlank]
        #[Assert\Length(max: self::MAX_LENGTH)]
        public string $lastName,
        #[Assert\NotBlank]
        #[Assert\Length(max: self::MAX_LENGTH)]
        public string $street,
        #[Assert\NotBlank]
        #[Assert\Length(max: self::MAX_LENGTH)]
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

    /** The address on one line, as a privacy export shows it. */
    public function oneLine(): string
    {
        $parts = [trim("{$this->firstName} {$this->lastName}"), $this->street, $this->city, $this->postcode, $this->state ?? '', $this->country, $this->phone];

        return implode(', ', array_filter(array_map(trim(...), $parts), static fn(string $part): bool => $part !== ''));
    }
}
