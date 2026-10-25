<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

use OrillaCart\Geography\Application\Dto\LocationView;
use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Tax\Application\Dto\ReverseCharge;
use OrillaCart\Tax\Application\Dto\VatCheck;
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
     * @param string|null $country where the goods go; null when nothing ships
     */
    public function forGoodsTo(ReverseCharge $reverse, ?string $country): ReverseCharge
    {
        if (!$reverse->applies || $country === null) {
            return $reverse;
        }
        $shopCountry = $this->shop->current()->shopCountry;
        $location = $this->geography->locate($country, null);
        if ($location instanceof LocationView && $location->inEu && $country !== $shopCountry) {
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
        if (!$location instanceof LocationView || !$location->inEu) {
            // Outside the EU the number goes on the order and the invoice, once its check digit holds.
            $checked = TaxNumber::check($billingCountry, $entered);

            return $checked === null
                ? new ReverseCharge(false, null, \sprintf('This is not a valid %s, so it is not on the order. Check it and try again, or leave it out.', TaxNumber::label($billingCountry)))
                : new ReverseCharge(false, $checked);
        }
        $prefix = self::PREFIXES[$billingCountry] ?? $billingCountry;
        $number = str_starts_with($entered, $prefix) ? substr($entered, \strlen($prefix)) : $entered;
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
        if ($this->geography->locate($country, null)?->inEu !== true) {
            $checked = TaxNumber::check($country, $typed);

            return new VatCheck($checked === null ? VatCheck::INVALID : VatCheck::VALID, $checked ?? $typed);
        }
        $prefix = self::PREFIXES[$country] ?? $country;
        $number = str_starts_with($typed, $prefix) ? substr($typed, \strlen($prefix)) : $typed;
        if (preg_match('/^[0-9A-Z]{2,12}$/', $number) !== 1) {
            return new VatCheck(VatCheck::INVALID, $typed);
        }

        return $this->registry->check($prefix, $number);
    }
}
