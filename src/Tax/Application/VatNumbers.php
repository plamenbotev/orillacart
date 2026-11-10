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
    /** Words of a business name that tell no business from another: legal forms, and what many have in their names. */
    private const array SAY_NOTHING = [
        'gmbh', 'ltd', 'llc', 'inc', 'sarl', 'srl', 'spa', 'eood', 'ood', 'bvba', 'the', 'and', 'und', 'plc', 'llp', 'kft', 'zrt', 'sas', 'sro',
        'company', 'compagnie', 'corporation', 'limited', 'group', 'gruppe', 'holding', 'holdings', 'services', 'service', 'trading', 'handel',
        'handels', 'international', 'industries', 'solutions', 'systems', 'consulting', 'partners', 'gesellschaft',
    ];

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

        return new ReverseCharge(false, $reverse->number, registeredName: $reverse->registeredName, message: $country === $shopCountry ? 'The goods are delivered in the shop\'s own country, so VAT is charged, even with a VAT number of another EU country.' : null);
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
            VatCheck::VALID => new ReverseCharge(true, $check->number, registeredName: $check->name),
            VatCheck::UNAVAILABLE => new ReverseCharge(false, $check->number, 'Your VAT number could not be checked right now, so VAT is charged. Order later to buy without it, or claim the VAT back.'),
            default => new ReverseCharge(false, $check->number, 'The EU does not know this VAT number, so VAT is charged.'),
        };
    }

    /**
     * Why the shop should look at an order sold under the reverse charge: the name VIES has the number registered
     * to is not the billing name, nor the email's domain. They agree when the billing name holds every telling word
     * of the registered one (Max Muster of Muster GmbH), or is a shortening of it (ACME for ACME Robotics AG), or
     * the email's domain is the name written as one (ada@acme-robotics.de); one word in common is not enough
     * (Deutsche Telekom is not Deutsche Bank). Legal forms and words any business has (GmbH, Group, Services) tell
     * nothing. Null when they agree, or VIES gave no name. The order is not refused: a business orders in its
     * staff's names.
     */
    public function nameMismatch(ReverseCharge $reverse, string $billingName, string $email): ?string
    {
        $registered = trim((string) $reverse->registeredName);
        if (!$reverse->applies || $registered === '' || trim($registered, '- ') === '') {
            return null;
        }
        $words = self::words($registered);
        if ($words === []) {
            return null;
        }
        $billing = self::words($billingName);
        $holdsAll = array_diff($words, $billing) === [];
        $shortening = $billing !== [] && array_diff($billing, $words) === [];
        $host = strtolower(substr($email, (int) strrpos($email, '@') + 1));
        $labels = \array_slice(explode('.', $host), 0, -1);
        $byDomain = array_intersect($labels, [implode('', $words), implode('-', $words)]) !== [];
        if ($holdsAll || $shortening || $byDomain) {
            return null;
        }

        return \sprintf('VIES has the VAT number %1$s registered to "%2$s", which is not the billing name "%3$s". Check that the buyer is that business: the order is sold without VAT.', (string) $reverse->number, $registered, trim($billingName));
    }

    /**
     * The telling words of a name, lowercased, each once: of 3 letters or more (legal forms shorter than that, SA
     * or AB, say nothing), without the legal forms and the words any business may have in its name.
     *
     * @return list<string>
     */
    private static function words(string $name): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name), -1, \PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            return [];
        }

        return array_values(array_unique(array_filter($words, static fn(string $word): bool => mb_strlen($word) >= 3 && !\in_array($word, self::SAY_NOTHING, true))));
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
