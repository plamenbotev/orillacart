<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

use OrillaCart\Geography\Application\Dto\LocationView;
use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Tax\Application\Dto\ReverseCharge;
use OrillaCart\Tax\Application\Dto\TaxAddress;
use OrillaCart\Tax\Application\Dto\VatCheck;
use OrillaCart\Tax\Domain\NorthernIreland;
use OrillaCart\Tax\Domain\TaxNumber;
use OrillaCart\Tax\Domain\TaxSettingsRepository;

/**
 * Business customers' tax numbers and the reverse charge: a business in another EU country than the shop's, with
 * a number VIES confirms, buys without VAT and accounts for it itself. A number that cannot be confirmed, because
 * VIES is down, is never trusted: VAT is charged, and the customer is told why. Outside the EU (TaxNumber), a number
 * whose check digit holds goes on the order and the invoice; one that does not is left out, and the customer told.
 */
final readonly class VatNumbers
{
    /** VIES's country codes that are not the ISO ones. */
    private const array PREFIXES = ['GR' => 'EL'];

    public function __construct(
        private VatRegistry $registry,
        private TaxSettingsRepository $settings,
        private SettingsService $shop,
        private GeographyService $geography,
    ) {}

    /** Whether checkout asks business customers for their VAT number. */
    public function asked(): bool
    {
        return $this->settings->load()->reverseCharge;
    }

    /**
     * Goods sold under the reverse charge leave for another EU country: delivered in the shop's own country they
     * are a sale at home, VAT due; sent out of the EU they are an export, taxed as the rates there say.
     *
     * Northern Ireland is in the EU for goods (NorthernIreland).
     *
     * @param TaxAddress|null $to where the goods go; null when nothing ships
     */
    public function forGoodsTo(ReverseCharge $reverse, ?TaxAddress $to): ReverseCharge
    {
        $country = $to?->country;
        if (!$reverse->applies || $country === null) {
            return $reverse;
        }
        $shopCountry = $this->shop->current()->shopCountry;
        $location = $this->geography->locate($country, null);
        $inEu = ($location instanceof LocationView && $location->inEu) || NorthernIreland::covers($country, $to?->postcode);
        if ($inEu && $country !== $shopCountry) {
            return $reverse;
        }

        return new ReverseCharge(false, $reverse->number, $country === $shopCountry ? 'The goods are delivered in the shop\'s own country, so VAT is charged, even with a VAT number of another EU country.' : null);
    }

    public function reverseCharge(?string $billingCountry, ?string $vatNumber): ReverseCharge
    {
        $entered = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $vatNumber ?? ''));
        if ($entered === '' || $billingCountry === null || !$this->asked()) {
            return ReverseCharge::none();
        }
        $location = $this->geography->locate($billingCountry, null);
        // A business in Northern Ireland has an XI number for its goods, which VIES checks.
        $northernIreland = $billingCountry === 'GB' && str_starts_with($entered, NorthernIreland::VIES_PREFIX);
        if (!$northernIreland && (!$location instanceof LocationView || !$location->inEu)) {
            // Outside the EU the number goes on the order and the invoice, once its check digit holds.
            $checked = TaxNumber::check($billingCountry, $entered);

            return $checked === null
                ? new ReverseCharge(false, null, \sprintf('This is not a valid %s, so it is not on the order. Check it and try again, or leave it out.', TaxNumber::label($billingCountry)))
                : new ReverseCharge(false, $checked);
        }
        $prefix = $northernIreland ? NorthernIreland::VIES_PREFIX : (self::PREFIXES[$billingCountry] ?? $billingCountry);
        $number = self::withoutPrefix($entered, $prefix, $billingCountry);
        if (preg_match('/^[0-9A-Z]{2,12}$/', $number) !== 1) {
            return new ReverseCharge(false, $entered, 'This is not a VAT number of the billing country, so VAT is charged.');
        }
        $shopCountry = $this->shop->current()->shopCountry;
        if ($billingCountry === $shopCountry) {
            // Reverse charge is for businesses in another EU country; those at home keep their number on the order.
            return new ReverseCharge(false, $prefix . $number);
        }
        $check = $this->registry->check($prefix, $number);

        return match ($check->status) {
            VatCheck::VALID => new ReverseCharge(true, $check->number),
            VatCheck::UNAVAILABLE => new ReverseCharge(false, $check->number, 'Your VAT number could not be checked right now, so VAT is charged. Order later to buy without it, or claim the VAT back.'),
            default => new ReverseCharge(false, $check->number, 'The EU does not know this VAT number, so VAT is charged.'),
        };
    }

    /**
     * Checks the shop's own number: an EU one with VIES, another country's by its check digits. Unavailable when
     * VIES cannot answer now.
     */
    public function checkSeller(string $country, string $entered): VatCheck
    {
        $typed = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $entered));
        $northernIreland = $country === 'GB' && str_starts_with($typed, NorthernIreland::VIES_PREFIX);
        if (!$northernIreland && $this->geography->locate($country, null)?->inEu !== true) {
            $checked = TaxNumber::check($country, $typed);

            return new VatCheck($checked === null ? VatCheck::INVALID : VatCheck::VALID, $checked ?? $typed);
        }
        $prefix = $northernIreland ? NorthernIreland::VIES_PREFIX : (self::PREFIXES[$country] ?? $country);
        $number = self::withoutPrefix($typed, $prefix, $country);
        if (preg_match('/^[0-9A-Z]{2,12}$/', $number) !== 1) {
            return new VatCheck(VatCheck::INVALID, $typed);
        }

        return $this->registry->check($prefix, $number);
    }

    /** The number without the prefix VIES knows the country by, or its ISO code: a Greek EL… or GR… alike. */
    private static function withoutPrefix(string $entered, string $prefix, string $country): string
    {
        foreach (array_unique([$prefix, $country]) as $start) {
            if (str_starts_with($entered, $start)) {
                return substr($entered, \strlen($start));
            }
        }

        return $entered;
    }
}
