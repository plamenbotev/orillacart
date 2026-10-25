<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Step;

use OrillaCart\Geography\Application\Dto\CountryView;
use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Invoicing\Application\Dto\Seller;
use OrillaCart\Invoicing\Application\Invoices;
use OrillaCart\Settings\Application\Dto\ShopLocation;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Settings\Domain\CountryDefaults;
use OrillaCart\Settings\Domain\CurrencyPosition;
use OrillaCart\Setup\Application\Dto\BusinessAnswers;
use OrillaCart\Setup\Application\Dto\PlannedChange;
use OrillaCart\Setup\Application\SetupAnswers;
use OrillaCart\Setup\Application\SetupStep;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Tax\Application\Dto\VatCheck;
use OrillaCart\Tax\Application\VatNumbers;

/**
 * Your business: where the shop sells from, in what money and units (the country's own, the currency the
 * owner chose), and who invoices name as the seller, with the VAT number the EU knows.
 */
final readonly class BusinessStep implements SetupStep
{
    public const string KEY = 'business';
    private const string LOCATION = 'business.location';
    private const string SELLER = 'business.seller';

    public function __construct(
        private SettingsService $settings,
        private Invoices $invoices,
        private GeographyService $geography,
        private VatNumbers $vatNumbers,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function position(): int
    {
        return 10;
    }

    public function answers(): string
    {
        return BusinessAnswers::class;
    }

    public function plan(SetupAnswers $answers): array
    {
        $business = $answers->of(self::KEY, BusinessAnswers::class);
        if ($business === null) {
            return [];
        }

        return array_values(array_filter([$this->location($business), $this->seller($business)]));
    }

    public function apply(PlannedChange $change, SetupAnswers $answers): void
    {
        $data = $change->data;
        match ($change->key) {
            self::LOCATION => $this->settings->locate(new ShopLocation(
                self::text($data, 'country'),
                self::optional($data, 'state'),
                self::optional($data, 'postcode'),
                self::text($data, 'currency'),
                self::text($data, 'sign'),
                self::text($data, 'position'),
                self::text($data, 'weight'),
                self::text($data, 'dimension'),
            )),
            self::SELLER => $this->invoices->changeSeller(new Seller(
                self::text($data, 'legal_name'),
                self::text($data, 'address'),
                self::text($data, 'vat_number'),
                self::text($data, 'company_number'),
                self::text($data, 'phone'),
                self::text($data, 'email'),
            )),
            default => null,
        };
    }

    private function location(BusinessAnswers $business): ?PlannedChange
    {
        $country = $this->country($business->country);
        $money = CountryDefaults::money($business->country);
        $own = $money !== null && $money['currency'] === $business->currency;
        $data = [
            'country' => $business->country,
            'state' => $business->state,
            'postcode' => $this->postcode($business),
            'currency' => $business->currency,
            'sign' => $own ? $money['sign'] : $business->currency,
            'position' => ($own ? $money['position'] : CurrencyPosition::AfterWithSpace)->value,
            'weight' => CountryDefaults::weightUnit($business->country)->value,
            'dimension' => CountryDefaults::dimensionUnit($business->country)->value,
        ];
        $current = $this->settings->current();
        $now = [
            'country' => $current->shopCountry,
            'state' => $current->shopState,
            'postcode' => $current->shopPostcode,
            'currency' => $current->currency,
            'sign' => $current->currencySign,
            'position' => $current->currencyPosition,
            'weight' => $current->weightUnit,
            'dimension' => $current->dimensionUnit,
        ];
        if ($data === $now) {
            return null;
        }
        // A shop set up before has prices in its currency; they are not converted.
        $existing = $current->shopCountry !== null && $current->currency !== $business->currency
            ? Text::of('Prices are in %1$s now; they stay the same numbers in %2$s.', $current->currency, $business->currency)
            : null;

        return new PlannedChange(
            self::LOCATION,
            self::KEY,
            Text::of(
                'The shop sells from %1$s. Prices are in %2$s, written %3$s; weights in %4$s and sizes in %5$s.',
                $country->name ?? $business->country,
                $business->currency,
                self::written((string) $data['sign'], CurrencyPosition::from((string) $data['position'])),
                (string) $data['weight'],
                (string) $data['dimension'],
            ),
            $existing,
            $data,
        );
    }

    private function seller(BusinessAnswers $business): ?PlannedChange
    {
        $check = $business->vatNumber === '' ? null : $this->vatNumbers->checkSeller($business->country, $business->vatNumber);
        $address = implode("\n", $this->geography->addressLines('', '', trim($business->street), trim($business->city), $this->postcode($business) ?? '', $business->country, $business->state));
        $data = [
            'legal_name' => trim($business->legalName),
            'address' => $address,
            'vat_number' => $check->number ?? '',
            'company_number' => trim($business->companyNumber),
            'phone' => trim($business->phone),
            'email' => trim($business->email),
        ];
        $current = $this->invoices->settings();
        $now = ['legal_name' => $current->legalName, 'address' => $current->address, 'vat_number' => $current->vatNumber, 'company_number' => $current->companyNumber, 'phone' => $current->phone, 'email' => $current->email];
        if ($data === $now) {
            return null;
        }
        $existing = $current->legalName !== '' && ($current->legalName !== $data['legal_name'] || $current->address !== $address)
            ? Text::of('Invoices name %s now.', $current->legalName)
            : null;

        return new PlannedChange(
            self::SELLER,
            self::KEY,
            $data['vat_number'] === ''
                ? Text::of('Invoices name the seller as %1$s, %2$s.', $data['legal_name'], str_replace("\n", ', ', $address))
                : Text::of('Invoices name the seller as %1$s, %2$s, VAT number %3$s.', $data['legal_name'], str_replace("\n", ', ', $address), $data['vat_number']),
            $existing,
            $data,
            match ($check?->status) {
                VatCheck::INVALID => Text::of('%s is not a valid VAT number: check it, or leave it out until you have one.', $check->number),
                VatCheck::UNAVAILABLE => Text::of('The EU could not check %s right now; the check before the shop opens tries again.', $check->number),
                default => null,
            },
        );
    }

    /** The postcode as the country writes it; as typed when it does not look like one, for the owner to see. */
    private function postcode(BusinessAnswers $business): ?string
    {
        if (trim($business->postcode) === '') {
            return null;
        }
        try {
            return $this->geography->postcode($business->country, $business->postcode);
        } catch (RuleViolation) {
            return trim($business->postcode);
        }
    }

    private function country(string $code): ?CountryView
    {
        foreach ($this->geography->countries() as $country) {
            if ($country->code === $code) {
                return $country;
            }
        }

        return null;
    }

    private static function written(string $sign, CurrencyPosition $position): string
    {
        return match ($position) {
            CurrencyPosition::Before => $sign . '10',
            CurrencyPosition::BeforeWithSpace => $sign . ' 10',
            CurrencyPosition::After => '10' . $sign,
            CurrencyPosition::AfterWithSpace => '10 ' . $sign,
        };
    }

    /** @param array<string, mixed> $data */
    private static function text(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return \is_string($value) ? $value : '';
    }

    /** @param array<string, mixed> $data */
    private static function optional(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
