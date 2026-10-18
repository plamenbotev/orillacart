<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application;

use OrillaCart\Geography\Application\Dto\LocationView;
use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Tax\Application\Dto\ReverseCharge;
use OrillaCart\Tax\Application\Dto\VatCheck;
use OrillaCart\Tax\Domain\TaxSettingsRepository;

/**
 * Business customers' VAT numbers and the reverse charge: a business in another EU country than the shop's, with
 * a number VIES confirms, buys without VAT and accounts for it itself. A number that cannot be confirmed, because
 * VIES is down, is never trusted: VAT is charged, and the customer is told why.
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

    public function reverseCharge(?string $billingCountry, ?string $vatNumber): ReverseCharge
    {
        $entered = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $vatNumber ?? ''));
        if ($entered === '' || $billingCountry === null || !$this->asked()) {
            return ReverseCharge::none();
        }
        $prefix = self::PREFIXES[$billingCountry] ?? $billingCountry;
        $number = str_starts_with($entered, $prefix) ? substr($entered, \strlen($prefix)) : $entered;
        if (preg_match('/^[0-9A-Z]{2,12}$/', $number) !== 1) {
            return new ReverseCharge(false, $entered, 'This is not a VAT number of the billing country, so VAT is charged.');
        }
        $location = $this->geography->locate($billingCountry, null);
        $shopCountry = $this->shop->current()->shopCountry;
        if (!$location instanceof LocationView || !$location->inEu || $billingCountry === $shopCountry) {
            // Reverse charge is for businesses in another EU country; others keep their number on the order.
            return new ReverseCharge(false, $prefix . $number);
        }
        $check = $this->registry->check($prefix, $number);

        return match ($check->status) {
            VatCheck::VALID => new ReverseCharge(true, $check->number),
            VatCheck::UNAVAILABLE => new ReverseCharge(false, $check->number, 'Your VAT number could not be checked right now, so VAT is charged. Order later to buy without it, or claim the VAT back.'),
            default => new ReverseCharge(false, $check->number, 'The EU does not know this VAT number, so VAT is charged.'),
        };
    }
}
