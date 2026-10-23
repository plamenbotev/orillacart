<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Catalog\Application\Dto\OfferedVariation;
use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Checkout\Domain\Tax;
use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Tax\Application\Dto\AppliedTaxView;
use OrillaCart\Tax\Application\Dto\TaxAddress;
use OrillaCart\Tax\Application\Dto\TaxQuery;
use OrillaCart\Tax\Application\TaxCalculator;

/**
 * Prices as the shop shows them, with tax or without it, whichever way they are entered (TaxSettings::$shownInShop
 * and $shownInCart). The catalog's are worked out at the customer's saved address, or the shop's for visitors, as
 * checkout would charge them; the cart's and checkout's from the bill itself.
 */
final readonly class ShownPrices
{
    public function __construct(
        private TaxCalculator $tax,
        private CustomerService $customers,
        private SettingsService $settings,
    ) {}

    /**
     * The offers' prices as the catalog shows them.
     *
     * @template K of array-key
     *
     * @param array<K, ProductOffer> $offers
     *
     * @return array<K, ProductOffer>
     */
    public function offers(array $offers, ?int $customerId): array
    {
        if ($offers === []) {
            return [];
        }
        $address = $this->address($customerId);
        $exempt = $this->tax->exemption($customerId, $address, $address) !== null;
        $withTax = $this->tax->showsTax(false);
        /** @var array<string, array{list<Tax>, list<Tax>}> $rates */
        $rates = [];
        $shown = [];
        foreach ($offers as $key => $offer) {
            $parts = [];
            foreach ($offer->taxParts as $part) {
                $group = $part['group'];
                $rates[(string) $group] ??= [
                    array_map(static fn(AppliedTaxView $tax): Tax => OrderPricing::tax($tax, $exempt), $this->tax->taxes(new TaxQuery($group, $address, $address))->taxes),
                    array_map(static fn(AppliedTaxView $tax): Tax => OrderPricing::tax($tax), $this->tax->shopTaxes($group)->taxes),
                ];
                $parts[] = [$part['taxable'], Money::of($part['weight']), ...$rates[(string) $group]];
            }
            $shown[$key] = $this->shownOffer($offer, $parts, $withTax);
        }

        return $shown;
    }

    public function offer(ProductOffer $offer, ?int $customerId): ProductOffer
    {
        return $this->offers([$offer], $customerId)[0];
    }

    /** Whether the cart and checkout show prices with tax. */
    public function cartShowsTax(): bool
    {
        return $this->tax->showsTax(true);
    }

    /**
     * An amount of the bill and its tax as the cart and checkout show it: with tax or without, the bill's own way
     * being $includesTax.
     */
    public static function amount(string $amount, string $tax, bool $includesTax, bool $withTax): Money
    {
        if ($withTax === $includesTax) {
            return Money::of($amount);
        }

        return $withTax ? Money::of($amount)->add(Money::of($tax)) : Money::of($amount)->subtract(Money::of($tax));
    }

    /**
     * A line of the bill as shown: its unit price (the total shared by the quantity), total and discount. The tax is on what the line costs after its
     * discount; the discount shown changes by the same share.
     *
     * @return array{string, string, string}
     */
    public static function line(string $unitPrice, int $quantity, string $total, string $discount, string $tax, bool $includesTax, bool $withTax): array
    {
        if ($withTax === $includesTax) {
            return [$unitPrice, $total, $discount];
        }
        $after = Money::of($total)->subtract(Money::of($discount));
        $shownAfter = self::amount((string) $after, $tax, $includesTax, $withTax);
        $shownDiscount = $after->isZero() ? Money::of($discount) : Money::of($discount)->proportion($shownAfter, $after);
        $shownTotal = $shownAfter->add($shownDiscount);
        $unit = $quantity > 0 ? $shownTotal->proportion(Money::of('1'), Money::of((string) $quantity)) : Money::of($unitPrice);

        return [(string) $unit, (string) $shownTotal, (string) $shownDiscount];
    }

    /**
     * @param list<array{bool, Money, list<Tax>, list<Tax>}> $parts taxable, weight, the customer's rates, the shop's
     */
    private function shownOffer(ProductOffer $offer, array $parts, bool $withTax): ProductOffer
    {
        $as = fn(string $price, bool $tax): string => (string) $this->shown(Money::of($price), $parts, $tax);
        $shown = static fn(string $price): string => $as($price, $withTax);

        return $offer->shown(
            $shown,
            array_map(static fn(OfferedVariation $variation): OfferedVariation => $variation->shown($shown, $as($variation->price, true), $as($variation->price, false)), $offer->variations),
            $withTax,
            $as($offer->price, true),
            $as($offer->price, false),
        );
    }

    /**
     * An entered price with tax or without, its parts each at their rates.
     *
     * @param list<array{bool, Money, list<Tax>, list<Tax>}> $parts
     */
    private function shown(Money $price, array $parts, bool $withTax): Money
    {
        if ($parts === []) {
            return $price;
        }
        $inclusive = $this->tax->pricesIncludeTax();
        $samePrice = $this->tax->samePriceEverywhere();
        $whole = array_reduce($parts, static fn(Money $sum, array $part): Money => $sum->add($part[1]), Money::zero());
        $left = $price;
        $shown = Money::zero();
        foreach ($parts as $index => [$taxable, $weight, $taxes, $shopTaxes]) {
            $amount = $index === \count($parts) - 1 ? $left : ($whole->isZero() ? $price->proportion(Money::of('1'), Money::of((string) \count($parts))) : $price->proportion($weight, $whole));
            $left = $left->subtract($amount);
            if (!$taxable) {
                $shown = $shown->add($amount);

                continue;
            }
            if ($inclusive) {
                $paid = Tax::asPaid($amount, $taxes, $shopTaxes, $samePrice);
                $net = Tax::without($paid, $taxes);
                $gross = $paid;
            } else {
                $net = $amount;
                $gross = array_reduce(Tax::parts($amount, $taxes), static fn(Money $sum, Money $part): Money => $sum->add($part), $amount);
            }
            $shown = $shown->add($withTax ? $gross : $net);
        }

        return $shown->rounded(4);
    }

    private function address(?int $customerId): ?TaxAddress
    {
        $saved = $customerId === null ? null : $this->customers->savedAddress($customerId);
        if ($saved !== null) {
            return new TaxAddress($saved->country, $saved->state, $saved->postcode, $saved->city);
        }
        $shop = $this->settings->current();

        return $shop->shopCountry === null || $shop->shopCountry === '' ? null : new TaxAddress($shop->shopCountry, $shop->shopState, $shop->shopPostcode);
    }
}
