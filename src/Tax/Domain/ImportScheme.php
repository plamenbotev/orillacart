<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Domain;

/**
 * A scheme under which a seller from elsewhere charges the VAT or GST on low-value goods it ships into a country
 * at checkout, instead of the customer paying it at the border: up to a limit on what the goods are worth before
 * shipping and tax, per order or per item.
 */
enum ImportScheme: string
{
    /** The EU's Import One-Stop-Shop: consignments up to €150, into any EU country. */
    case Ioss = 'ioss';
    /** The UK's: consignments up to £135. */
    case Uk = 'uk';
    /** Norway's VAT On E-Commerce: items up to NOK 3,000. */
    case Voec = 'voec';
    /** Australia's GST on low-value imports: items up to A$1,000. */
    case Australia = 'au';
    /** New Zealand's GST on low-value imports: consignments up to NZ$1,000. */
    case NewZealand = 'nz';

    public function label(): string
    {
        return match ($this) {
            self::Ioss => 'EU Import One-Stop-Shop (IOSS)',
            self::Uk => 'United Kingdom',
            self::Voec => 'Norway (VOEC)',
            self::Australia => 'Australia',
            self::NewZealand => 'New Zealand',
        };
    }

    /** Whether goods shipped to the country come under it. */
    public function covers(string $country, bool $inEu): bool
    {
        return match ($this) {
            self::Ioss => $inEu,
            self::Uk => $country === 'GB',
            self::Voec => $country === 'NO',
            self::Australia => $country === 'AU',
            self::NewZealand => $country === 'NZ',
        };
    }

    /** The limit the law sets, in its own currency. */
    public function limit(): string
    {
        return match ($this) {
            self::Ioss => '150',
            self::Uk => '135',
            self::Voec => '3000',
            self::Australia, self::NewZealand => '1000',
        };
    }

    public function currency(): string
    {
        return match ($this) {
            self::Ioss => 'EUR',
            self::Uk => 'GBP',
            self::Voec => 'NOK',
            self::Australia => 'AUD',
            self::NewZealand => 'NZD',
        };
    }

    /** The limit holds for each item, not for the order. */
    public function perItem(): bool
    {
        return $this === self::Voec || $this === self::Australia;
    }

    /** What the seller's number there looks like, without spaces or dashes. */
    public function numberPattern(): string
    {
        return match ($this) {
            self::Ioss => '/^IM\d{10}$/',
            self::Uk => '/^GB(\d{9}|\d{12})$/',
            self::Voec => '/^\d{7}$/',
            self::Australia => '/^\d{11,12}$/',
            self::NewZealand => '/^\d{8,9}$/',
        };
    }

    public function numberExample(): string
    {
        return match ($this) {
            self::Ioss => 'IM2760000001',
            self::Uk => 'GB123456789',
            self::Voec => '2024123',
            self::Australia => '51824753556',
            self::NewZealand => '123456789',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $scheme): string => $scheme->value, self::cases());
    }
}
