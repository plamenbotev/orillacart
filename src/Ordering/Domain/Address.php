<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A postal address as the customer gave it at checkout. Orders keep it unchanged.
 */
#[ORM\Embeddable]
final class Address
{
    public const int MAX_LENGTH = 200;

    #[ORM\Column(length: self::MAX_LENGTH, nullable: true)]
    private ?string $firstName;

    #[ORM\Column(length: self::MAX_LENGTH, nullable: true)]
    private ?string $lastName;

    #[ORM\Column(length: self::MAX_LENGTH, nullable: true)]
    private ?string $street;

    #[ORM\Column(length: self::MAX_LENGTH, nullable: true)]
    private ?string $city;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $postcode;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $country;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $state;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $phone;

    private function __construct(?string $firstName, ?string $lastName, ?string $street, ?string $city, ?string $postcode, ?string $country, ?string $state, ?string $phone)
    {
        $this->firstName = $firstName;
        $this->lastName = $lastName;
        $this->street = $street;
        $this->city = $city;
        $this->postcode = $postcode;
        $this->country = $country;
        $this->state = $state;
        $this->phone = $phone;
    }

    /**
     * @throws OrderRuleViolation for a missing name, street or city, or a malformed country or state code
     */
    public static function of(string $firstName, string $lastName, string $street, string $city, string $postcode, string $country, ?string $state, string $phone): self
    {
        foreach (['first name' => $firstName, 'last name' => $lastName, 'street' => $street, 'city' => $city] as $name => $value) {
            if (trim($value) === '' || mb_strlen(trim($value)) > self::MAX_LENGTH) {
                throw new OrderRuleViolation(\sprintf('An address needs a %s of up to %d characters.', $name, self::MAX_LENGTH), 'orillacart_invalid_address');
            }
        }
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1 || ($state !== null && preg_match('/^[A-Z0-9]{1,3}$/', $state) !== 1)) {
            throw new OrderRuleViolation('An address needs a known country and, where the country has them, a state.', 'orillacart_invalid_address');
        }

        return new self(trim($firstName), trim($lastName), trim($street), trim($city), mb_substr(trim($postcode), 0, 20), $country, $state, mb_substr(trim($phone), 0, 40));
    }

    /**
     * This address with the person gone, as a privacy request asks: the country and state stay, since the books
     * need them for the tax charged.
     */
    public function anonymised(): self
    {
        return new self('Anonymised', '', null, null, null, $this->country, $this->state, null);
    }

    /** No address: the columns an order that ships nothing leaves empty. */
    public static function none(): self
    {
        return new self(null, null, null, null, null, null, null, null);
    }

    public function firstName(): string
    {
        return (string) $this->firstName;
    }

    public function lastName(): string
    {
        return (string) $this->lastName;
    }

    public function street(): string
    {
        return (string) $this->street;
    }

    public function city(): string
    {
        return (string) $this->city;
    }

    public function postcode(): string
    {
        return (string) $this->postcode;
    }

    public function country(): string
    {
        return (string) $this->country;
    }

    public function state(): ?string
    {
        return $this->state;
    }

    public function phone(): string
    {
        return (string) $this->phone;
    }

    /** Whether this is none(): an order that ships nothing has no shipping address. */
    public function isEmpty(): bool
    {
        return $this->country === null;
    }
}
