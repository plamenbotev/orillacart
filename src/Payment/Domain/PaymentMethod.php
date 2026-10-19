<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * How the shop set up one payment gateway: whether checkout offers it, under which name, in which countries,
 * what the customer reads before and after ordering, and the gateway's own settings (such as PayPal's keys).
 */
#[ORM\Entity]
#[ORM\Table(name: 'payment_methods')]
#[ORM\UniqueConstraint(name: 'payment_method_gateway', columns: ['gateway'])]
class PaymentMethod
{
    public const int MAX_NAME_LENGTH = 100;
    public const int MAX_TEXT_LENGTH = 5000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private string $gateway;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    private string $name;

    #[ORM\Column]
    private bool $active;

    #[ORM\Column]
    private int $position;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $countries;

    #[ORM\Column(type: 'text')]
    private string $instructions;

    #[ORM\Column(type: 'text')]
    private string $receiptNote;

    /** @var array<string, string|bool> */
    #[ORM\Column(type: 'sealed_json')]
    private array $settings;

    /**
     * @param list<string>               $countries ISO 3166-1 codes where checkout offers it; empty for everywhere
     * @param array<string, string|bool> $settings  the gateway's own settings, checked by the gateway
     */
    public function __construct(string $gateway, string $name, bool $active = false, int $position = 0, array $countries = [], string $instructions = '', string $receiptNote = '', array $settings = [])
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $gateway) !== 1) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a gateway id.', $gateway));
        }
        $this->gateway = $gateway;
        $this->change($name, $active, $position, $countries, $instructions, $receiptNote, $settings);
    }

    /**
     * @param list<string>               $countries
     * @param array<string, string|bool> $settings
     */
    public function change(string $name, bool $active, int $position, array $countries, string $instructions, string $receiptNote, array $settings): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new PaymentRuleViolation(\sprintf('A payment method needs a name of up to %d characters.', self::MAX_NAME_LENGTH), 'orillacart_invalid_payment_method_name');
        }
        if (mb_strlen($instructions) > self::MAX_TEXT_LENGTH || mb_strlen($receiptNote) > self::MAX_TEXT_LENGTH) {
            throw new PaymentRuleViolation(\sprintf('Texts of a payment method have up to %d characters.', self::MAX_TEXT_LENGTH), 'orillacart_invalid_payment_method_text');
        }
        foreach ($countries as $country) {
            if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
                throw new PaymentRuleViolation(\sprintf('"%s" is not a country code.', $country), 'orillacart_invalid_country');
            }
        }
        $this->name = $name;
        $this->active = $active;
        $this->position = $position;
        $this->countries = array_values(array_unique($countries));
        $this->instructions = trim($instructions);
        $this->receiptNote = trim($receiptNote);
        $this->settings = $settings;
    }

    public function gateway(): string
    {
        return $this->gateway;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function position(): int
    {
        return $this->position;
    }

    /** @return list<string> */
    public function countries(): array
    {
        return $this->countries;
    }

    public function instructions(): string
    {
        return $this->instructions;
    }

    public function receiptNote(): string
    {
        return $this->receiptNote;
    }

    /** @return array<string, string|bool> */
    public function settings(): array
    {
        return $this->settings;
    }

    /** Whether a customer billed in this country may use it; without a country, only methods for everywhere. */
    public function servesCountry(?string $country): bool
    {
        return $this->countries === [] || ($country !== null && \in_array($country, $this->countries, true));
    }
}
