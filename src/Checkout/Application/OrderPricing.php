<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Catalog\Application\Dto\ItemQuote;
use OrillaCart\Catalog\Application\Dto\QuotedBundleItem;
use OrillaCart\Catalog\Application\Dto\QuotedOption;
use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Application\ProductQuotes;
use OrillaCart\Checkout\Application\Dto\ShippingChoiceView;
use OrillaCart\Checkout\Domain\Bill;
use OrillaCart\Checkout\Domain\BilledCharge;
use OrillaCart\Checkout\Domain\ChargedTax;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Checkout\Domain\Tax;
use OrillaCart\Coupons\Application\Discounts;
use OrillaCart\Coupons\Application\Dto\AppliedCoupon;
use OrillaCart\Coupons\Application\Dto\AppliedDiscounts;
use OrillaCart\Coupons\Application\Dto\DiscountItem;
use OrillaCart\Coupons\Application\Dto\DiscountRequest;
use OrillaCart\Extension\Checkout\FeeItem;
use OrillaCart\Extension\Checkout\FeeQuery;
use OrillaCart\Extension\Tax\TaxAddress as ExtensionTaxAddress;
use OrillaCart\Extension\Tax\TaxLine;
use OrillaCart\Extension\Tax\TaxRequest;
use OrillaCart\Extension\Tax\TaxRequestLine;
use OrillaCart\GiftCards\Application\GiftCardProducts;
use OrillaCart\Ordering\Application\Dto\AdjustmentView;
use OrillaCart\Ordering\Application\Dto\NewShipping;
use OrillaCart\Ordering\Application\Dto\OrderDiscountView;
use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Settings\Application\ShopCalendar;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Domain\TaxJurisdiction;
use OrillaCart\Shipping\Application\Dto\ParcelItem;
use OrillaCart\Shipping\Application\Dto\PickupPointView;
use OrillaCart\Shipping\Application\Dto\ShippingQuery;
use OrillaCart\Shipping\Application\ShippingQuotes;
use OrillaCart\Tax\Application\Dto\AppliedTaxView;
use OrillaCart\Tax\Application\Dto\ImportDecision;
use OrillaCart\Tax\Application\Dto\ReverseCharge;
use OrillaCart\Tax\Application\Dto\TaxAddress;
use OrillaCart\Tax\Application\Dto\TaxExemptionView;
use OrillaCart\Tax\Application\Dto\TaxQuery;
use OrillaCart\Tax\Application\ImportSchemes;
use OrillaCart\Tax\Application\TaxCalculator;
use OrillaCart\Tax\Application\TaxServices;
use OrillaCart\Tax\Application\TaxServiceUnavailable;
use Psr\Clock\ClockInterface;

/**
 * Prices items as an order: catalog prices, taxes at the customer's address, shipping, fees and the bill; and turns
 * a priced order into order lines, taking their stock. Checkout and order editing price the same way.
 *
 * Coupons take money off the items before tax is charged, and free shipping makes the cheapest way to ship cost nothing
 * (every way, when the coupon says so).
 * The items coupons give (GivenItems) are items like any other: the coupon that gives one takes its price off.
 *
 * @phpstan-type PricedLine array{quote: ItemQuote, option_ids: list<int>, bundle_choices: list<int>, quantity: int, unit_price: string, total: Money, discount: Money, tax: Money, places: list<int>, given: ?string, add_on: array{type: string, label: string}|null, personalisation: list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}>}
 * @phpstan-type FeeLine array{type: string, quantity: int, unit: Money, source: ?string, taxable: bool, tax_group_id: ?int, as_line: bool, for_product_ids?: list<int>}
 * @phpstan-type Charge array{label: string, amount: string, quantity: int, taxable: bool, tax_group_id: ?int, source: string, given?: bool, for_product_ids?: list<int>}
 * @phpstan-type Priced array{exemption: array{reason: string, certificate: string}|null, import: ImportDecision, lines: list<PricedLine>, bill: Bill, needs_shipping: bool, options: list<ShippingChoiceView>, chosen: ?ShippingChoiceView, discounts: AppliedDiscounts, coupon_lines: list<list<Money>>, changes: array<int, list<AdjustmentView>>, free_from: ?string, ships_from: ?string, shop_shipping: array{carrier: string, rate: string, collect_at: ?PickupPointView}|null, shop_fees: list<string>, fee_lines: list<FeeLine>, sized: Money}
 */
final readonly class OrderPricing
{
    public function __construct(
        private ProductQuotes $quotes,
        private TaxCalculator $tax,
        private ShippingQuotes $shipping,
        private SettingsService $settings,
        private Discounts $discounts,
        private OrderFees $fees,
        private Currencies $currencies,
        private TaxServices $taxServices,
        private ClockInterface $clock,
        private ImportSchemes $importSchemes,
        private ShopCalendar $calendar,
        private GiftCardProducts $giftCards,
        private ExtensionPriceChanges $priceChanges,
    ) {}

    /**
     * Prices items with the customer's addresses: taxes, the shipping rates offered and the chosen one (or the
     * first), and the bill. An item may carry its own unit price, which the shop sets when it edits an order or
     * keeps from it.
     *
     * @param list<array{product_id: int, option_ids: list<int>, bundle_choices?: list<int>, quantity: int, unit_price?: ?string, given?: ?string, add_on?: array{type: string, label: string}|null, extra?: string, personalisation?: list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}>}> $items
     *                                    extra: what the answers to the product's forms add to each item; personalisation: those answers
     * @param string|null  $postcode   the shipping address's postcode, for rates that ship to some postcodes only
     * @param list<string> $codes      coupon codes; those that do not hold are left out, with the reason
     * @param string|null  $email      the customer's, for limits per customer, once known
     * @param int|null     $orderId    the order being priced again, when the shop edits it
     * @param bool         $reverseCharge a business in another EU country buys without VAT
     * @param string|null  $payment    the way to pay chosen, for its fee
     * @param list<array{kind: string, source: string, label: string, amount: string, line: ?int, reason: ?string, change: ?string}> $changes
     *                                    changes to the prices besides coupons: the shop's or add-ons', for a line by
     *                                    its index among the items, or for the whole order (line null)
     * @param string|null  $currency   what the order is priced in: the shop's prices converted, when it offers it;
     *                                 the shop's own currency when null. Unit prices given are in it already,
     *                                 and are what the customer pays: they do not go through Tax::asPaid() again.
     * @param list<string>|null $automatic the automatic discounts that may apply, by code; null for the shop's now
     * @param bool $itemsOnly the items and what the coupons take off them only: no shipping, fees or tax service
     *                        asked, for what coupons give (GivenItems)
     * @param array<string, string> $fields the answers to checkout's questions so far, by field id, for the add-ons' order adjusters
     * @param list<Charge> $charges prices of their own, with no product (an add-on's deposit, the shop's gift
     *                              wrapping): so much each in the shop's currency as its prices are, or, given, in the
     *                              order's as the customer pays it; no coupon takes anything off them
     *
     * @return Priced
     *
     * @throws CheckoutRuleViolation for an item no longer sold, or not in that quantity
     */
    public function price(array $items, ?TaxAddress $shipping, ?TaxAddress $billing, ?string $shippingOption, ?string $postcode = null, array $codes = [], ?int $customerId = null, ?string $email = null, ?int $orderId = null, bool $reverseCharge = false, ?string $payment = null, ?string $currency = null, array $changes = [], ?array $automatic = null, ?bool $reverseChargeGoods = null, bool $itemsOnly = false, array $charges = [], array $fields = []): array
    {
        $currency ??= $this->settings->current()->currency;
        $priced = $this->priceItems($items, $shipping, $billing, $shippingOption, $postcode, $codes, $customerId, $email, $orderId, $reverseCharge, $payment, $currency, $changes, $automatic, $reverseChargeGoods, itemsOnly: $itemsOnly, charges: $charges, fields: $fields);
        // Collected at one of the shop's places: the goods are handed over there, so they are taxed there, at
        // home (no reverse charge for goods, no import or export), whatever the shopper's address. A courier's
        // office or locker is still delivery to the customer: it changes nothing.
        $handedOver = $this->handedOverAt($priced);
        $goods = $handedOver === null ? $reverseChargeGoods : false;
        if ($handedOver !== null) {
            $priced = $this->priceItems($items, $shipping, $billing, $shippingOption, $postcode, $codes, $customerId, $email, $orderId, $reverseCharge, $payment, $currency, $changes, $automatic, $goods, $handedOver, charges: $charges, fields: $fields);
        }

        return $priced;
    }

    /**
     * Whether an item given is not given after all, as priced: the coupon that gives it did not hold, or took off
     * less than the items it gives cost (one it leaves out, as on sale). Its cart is then kept in step again.
     *
     * @param array{lines: list<array{given: ?string, total: Money, ...}>, discounts: AppliedDiscounts, ...} $priced
     */
    public static function unearnedGiven(array $priced, int $decimals): bool
    {
        $given = [];
        foreach ($priced['lines'] as $line) {
            if ($line['given'] !== null) {
                $given[$line['given']] = ($given[$line['given']] ?? Money::zero())->add($line['total']);
            }
        }
        foreach ($given as $code => $cost) {
            $coupon = array_find($priced['discounts']->coupons, static fn(AppliedCoupon $coupon): bool => $coupon->code === (string) $code);
            if ($coupon === null || Money::of($coupon->amount)->rounded($decimals)->compare($cost->rounded($decimals)) < 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The shop's amount an amount in the shopper's currency stands for, for comparing with the shop's thresholds:
     * the roundest of the shop's amounts the page would show as it. 97.79 BGN is 50 EUR (shown as 97.79), not
     * the 49.9992 dividing by the rate gives, which would fall short of a band from 50.
     */
    private function shopAmount(Money $shown, string $currency): Money
    {
        $back = $this->currencies->back($shown, $currency);
        if ($currency === $this->settings->current()->currency) {
            return $back;
        }
        for ($decimals = 0; $decimals < Money::DECIMALS; ++$decimals) {
            $round = $back->rounded($decimals);
            if ($this->currencies->amount($round, $currency)->compare($shown) === 0) {
                return $round;
            }
        }

        return $back;
    }

    /**
     * Where the goods are handed over when the customer collects them at one of the shop's places: the shop's
     * address (its places are where it is). Null for an order delivered, or a shop without its address.
     *
     * @param array{needs_shipping: bool, chosen: ?ShippingChoiceView, ...} $priced
     */
    private function handedOverAt(array $priced): ?TaxAddress
    {
        $shop = $this->settings->current();
        if (!$priced['needs_shipping'] || $priced['chosen']?->collectAt === null || $shop->shopCountry === null || $shop->shopCountry === '') {
            return null;
        }

        return new TaxAddress($shop->shopCountry, $shop->shopState, $shop->shopPostcode);
    }

    /**
     * Where the goods go, for their reverse charge (VatNumbers::forGoodsTo()): the shop's address when the
     * customer collects them at one of its places, the shipping address otherwise; null when nothing ships.
     *
     * @param array{needs_shipping: bool, chosen: ?ShippingChoiceView, ...} $priced
     */
    public function goodsTo(array $priced, ?TaxAddress $shipping): ?TaxAddress
    {
        if (!$priced['needs_shipping']) {
            return null;
        }

        return $this->handedOverAt($priced) ?? $shipping;
    }

    /**
     * The order's reverse charge once its goods were not sold under it: still the order's for what it sells besides
     * goods (services, downloads), whose lines it leaves untaxed; none when it sells only goods.
     *
     * @param array{lines: list<array{quote: ItemQuote, ...}>, ...} $priced
     */
    public static function underReverseCharge(ReverseCharge $reverse, ReverseCharge $goods, array $priced): ReverseCharge
    {
        if ($goods === $reverse) {
            return $reverse;
        }
        $services = array_filter($priced['lines'], static fn(array $line): bool => $line['quote']->type !== 'physical');

        return $reverse->applies && $services !== [] ? new ReverseCharge(true, $reverse->number, $goods->message, $reverse->registeredName) : $goods;
    }

    /**
     * The priced items as coupons see them, with who orders them to where: for the promotions the cart could still
     * get (Promotions::forCart()), measured on the items checkout applies its coupons to.
     *
     * @param array{lines: list<PricedLine>, ...} $priced
     */
    public function discountRequest(array $priced, ?string $country, ?int $customerId, string $currency): DiscountRequest
    {
        return new DiscountRequest($this->discountItems($priced['lines']), $currency, $country, $customerId, rate: $this->currencies->rate($currency));
    }

    /**
     * The items as coupons see them. Items coupons give and gift cards do not count towards the order's size.
     *
     * @param list<PricedLine> $lines
     *
     * @return list<DiscountItem>
     */
    private function discountItems(array $lines): array
    {
        $giftCards = $this->giftCards->ids();

        return array_map(static fn(array $line): DiscountItem => new DiscountItem($line['quote']->productId, $line['quantity'], $line['total']->toFixed(), $line['quote']->onSale, $line['quote']->categoryIds, $line['quote']->brandIds, $line['quote']->coupons, $line['given'] === null && !\in_array($line['quote']->productId, $giftCards, true), $line['given']), $lines);
    }

    /**
     * @param list<array{product_id: int, option_ids: list<int>, bundle_choices?: list<int>, quantity: int, unit_price?: ?string, given?: ?string, add_on?: array{type: string, label: string}|null, extra?: string, personalisation?: list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}>}> $items
     *                                    extra: what the answers to the product's forms add to each item; personalisation: those answers
     * @param list<array{kind: string, source: string, label: string, amount: string, line: ?int, reason: ?string, change: ?string}> $changes
     * @param list<string> $codes
     * @param list<string>|null $automatic
     * @param list<Charge> $charges
     * @param array<string, string> $fields the answers to checkout's questions so far, for the order adjusters
     *
     * @return Priced
     */
    private function priceItems(array $items, ?TaxAddress $shipping, ?TaxAddress $billing, ?string $shippingOption, ?string $postcode, array $codes, ?int $customerId, ?string $email, ?int $orderId, bool $reverseCharge, ?string $payment, string $currency, array $changes = [], ?array $automatic = null, ?bool $reverseChargeGoods = null, ?TaxAddress $handedOver = null, bool $itemsOnly = false, array $charges = [], array $fields = []): array
    {
        // Where the goods are taxed: where they are handed over (the shop's place they are collected at), else
        // where they are shipped. Which ways to ship are offered still goes by the shipping address.
        $taxAt = $handedOver ?? $shipping;
        // The reverse charge goes by what is sold: services by where the buyer is, goods (and their shipping) by
        // where they go (VatNumbers::forGoodsTo()).
        $goodsReverse = $reverseChargeGoods ?? $reverseCharge;
        $allReverse = $reverseCharge && $goodsReverse;
        $settings = $this->settings->current();
        $decimals = $this->currencies->decimals($currency);
        $inclusive = $this->tax->pricesIncludeTax();
        $samePrice = $this->tax->samePriceEverywhere();
        $bill = new Bill($decimals, $inclusive, $this->tax->roundsEachLine());
        // A customer's exemption covers the sale where it is taxed: the rates still apply, at nothing.
        $exemption = $allReverse ? null : $this->tax->exemption($customerId, $taxAt, $billing);
        $exempt = $exemption !== null;
        /** @var list<array{kind: string, code: int|string|null, quantity: int, product: ?int, sku: ?string, tax_code: ?string}> $asked */
        $asked = [];
        $lines = [];
        $parcel = [];
        $taxesOf = [];
        // Prices by quantity count every line of the product bought: its variations and option choices together,
        // not the items a discount gives with them (a free third lamp does not make the two bought cheaper).
        $ofProduct = [];
        foreach ($items as $line) {
            $bought = ($line['given'] ?? null) === null ? $line['quantity'] : 0;
            $ofProduct[$line['product_id']] = ($ofProduct[$line['product_id']] ?? 0) + $bought;
        }
        $quoted = [];
        foreach ($items as $index => $line) {
            try {
                $quote = $this->quotes->quote(new QuoteRequest($line['product_id'], $line['option_ids'], max(1, $ofProduct[$line['product_id']]), $customerId, $line['bundle_choices'] ?? [], $line['extra'] ?? '0'));
            } catch (NotFound|RuleViolation) {
                throw new CheckoutRuleViolation('An item is no longer sold. Review the items and try again.', 'orillacart_cart_changed');
            }
            // Not for sale says why: a draft or a product taken off sale is not a matter of quantity.
            $why = match ($quote->forSale ? null : $quote->notForSale) {
                ItemQuote::UNPUBLISHED => '%s is not published: it is a draft or waits for review, and only a published product is ordered.',
                ItemQuote::NOT_PURCHASABLE => '%s is not sold on its own: the shop marked it not purchasable.',
                ItemQuote::PRICE_HIDDEN => '%s is not sold while its price is hidden.',
                ItemQuote::BUNDLE_ITEM => '%s holds an item that is not for sale now. Review the items and try again.',
                default => null,
            };
            if ($why !== null) {
                throw new CheckoutRuleViolation($why, 'orillacart_cart_changed', values: [$quote->name]);
            }
            if (!$quote->forSale || ($quote->available !== null && $quote->available < $line['quantity'])) {
                throw new CheckoutRuleViolation('%s is not available in that quantity. Review the items and try again.', 'orillacart_cart_changed', values: [$quote->name]);
            }
            $quoted[$index] = $quote;
        }
        $import = $goodsReverse ? new ImportDecision() : $this->importDecision($items, $quoted, $taxAt, $currency, $inclusive);
        foreach ($items as $index => $line) {
            $quote = $quoted[$index];
            // Goods shipped under an import scheme: taxed at the destination when the shop charges it, otherwise not.
            $imported = $import->applies() && $quote->type === 'physical';
            // A bundle is taxed per item: its price shared out by what its items cost, each share at its item's rates.
            $parts = [];
            // A price given for the line (one the order kept, or the shop's own) is what this customer pays already;
            // in the currency's cents, as the shop can charge it: 1.2345 given is 1.23.
            $given = isset($line['unit_price']);
            $unitPrice = $given ? Money::of($line['unit_price'])->rounded($decimals) : $this->currencies->price(Money::of($quote->unitPrice), $currency);
            $units = PriceShares::shares($unitPrice, $quote->contents === [] ? [Money::of('1')] : array_map(static fn(QuotedBundleItem $item): Money => Money::of($item->price), $quote->contents));
            foreach ($quote->contents === [] ? [[$quote->taxable, $quote->taxGroupId, $quote->taxCode]] : array_map(static fn(QuotedBundleItem $item): array => [$item->taxable, $item->taxGroupId, $item->taxCode], $quote->contents) as $index => [$taxable, $groupId, $taxCode]) {
                // Taxed unless it is not taxable, sold under the reverse charge, or imported without the destination's tax.
                $taxed = $taxable && !($quote->type === 'physical' ? $goodsReverse : $reverseCharge) && (!$imported || $import->charges);
                $taxes = $taxed ? $this->taxes($groupId, $taxAt, $billing, $exempt, atShipping: $imported, goods: $quote->type === 'physical') : [];
                $unit = $units[$index];
                if ($inclusive && $taxable && !$given) {
                    // A price including tax: what this customer pays for it, as the shop chose (Tax::asPaid()).
                    // In the currency's cents: 10.00 with 20 % in it is 8.33 without, not 8.3333.
                    $unit = Tax::asPaid($unit, $taxes, $this->shopTaxes($groupId), $samePrice)->rounded($decimals);
                }
                $parts[] = ['unit' => $unit, 'taxes' => $taxes, 'group' => $groupId, 'tax_code' => $taxCode, 'taxed' => $taxed];
            }
            $unitPrice = (string) array_reduce($parts, static fn(Money $sum, array $part): Money => $sum->add($part['unit']), Money::zero());
            $total = Money::of($unitPrice)->times($line['quantity']);
            $taxesOf[] = array_map(static fn(array $part): array => ['total' => $part['unit']->times($line['quantity']), 'taxes' => $part['taxes'], 'group' => $part['group'], 'tax_code' => $part['tax_code'], 'taxed' => $part['taxed']], $parts);
            $lines[] = ['quote' => $quote, 'option_ids' => $line['option_ids'], 'bundle_choices' => $line['bundle_choices'] ?? [], 'quantity' => $line['quantity'], 'unit_price' => $unitPrice, 'total' => $total, 'discount' => Money::zero(), 'tax' => Money::zero(), 'places' => [], 'given' => $line['given'] ?? null, 'add_on' => $line['add_on'] ?? null, 'personalisation' => $line['personalisation'] ?? []];
            if ($quote->type === 'physical') {
                $parcel[] = new ParcelItem($line['quantity'], $quote->weight, $quote->length, $quote->width, $quote->height, $quote->shippingClassId);
            }
        }
        $discounts = $lines === [] ? new AppliedDiscounts() : $this->discounts->apply($codes, new DiscountRequest(
            $this->discountItems($lines),
            $currency,
            $shipping?->country,
            $customerId,
            $email,
            $orderId,
            $this->currencies->rate($currency),
            $automatic,
        ));
        $couponLines = PriceShares::roundedCoupons($discounts, array_map(static fn(array $line): Money => $line['total'], $lines), $decimals);
        foreach ($lines as $index => $line) {
            $lines[$index]['discount'] = array_reduce($couponLines, static fn(Money $sum, array $taken): Money => $sum->add($taken[$index]), Money::zero());
        }
        $adjusted = $this->priceChanges->of($changes, $lines, $decimals, $currency, $customerId, $email, $orderId, $fields);
        $places = [];
        foreach ($lines as $index => $line) {
            foreach ($adjusted[$index] ?? [] as $change) {
                $lines[$index]['discount'] = $lines[$index]['discount']->subtract(Money::of($change->amount));
            }
            if ($lines[$index]['discount']->compare($lines[$index]['total']) > 0) {
                throw new CheckoutRuleViolation('The changes to the price of %s take off more than it costs.', 'orillacart_invalid_price_change', values: [$lines[$index]['quote']->name]);
            }
            $parts = $taxesOf[$index];
            $discountShares = PriceShares::shares($lines[$index]['discount'], array_map(static fn(array $part): Money => $part['total'], $parts));
            foreach ($parts as $part => ['total' => $partTotal, 'taxes' => $taxes, 'group' => $groupId, 'tax_code' => $taxCode, 'taxed' => $taxed]) {
                $places[$index][] = $bill->addItem($partTotal, $taxes, $discountShares[$part], $line['quote']->name);
                // A tax service hears 'none' for what is not taxed (a gift card, a line under the reverse charge
                // or imported without the destination's tax): its answer stands in for the shop's rates.
                $asked[] = ['kind' => TaxRequestLine::ITEM, 'code' => $taxed ? $groupId : 'none', 'quantity' => $line['quantity'], 'product' => $line['quote']->productId, 'sku' => $line['quote']->sku, 'tax_code' => $taxed ? $taxCode : null];
            }
        }
        $lineTaxes = $bill->lineTaxes();
        foreach ($lines as $index => $line) {
            $lines[$index]['tax'] = array_reduce($places[$index] ?? [], static fn(Money $sum, int $place): Money => $sum->add($lineTaxes[$place]), Money::zero());
            $lines[$index]['places'] = $places[$index] ?? [];
        }
        $free = $discounts->freeShipping();
        // What thresholds judge (free shipping, the minimum order): the items after discounts, gift cards left out,
        // as a gift card is money to spend, not goods; one bought would otherwise ship the rest free.
        $giftCards = $this->giftCards->ids();
        $sized = $bill->subtotal()->subtract($bill->discount());
        foreach ($lines as $line) {
            if (\in_array($line['quote']->productId, $giftCards, true)) {
                $sized = $sized->subtract($line['total'])->add($line['discount']);
            }
        }

        $needsShipping = $parcel !== [];
        $options = [];
        $chosen = null;
        $freeFrom = null;
        $shipsFrom = null;
        if ($needsShipping && !$itemsOnly) {
            // Rates are the shop's: they look at what the order is worth in its currency, as the shopper's amount
            // stands for it (Currencies::amount() of a band's end is what the page shows).
            $query = new ShippingQuery($shipping?->country, $shipping?->state, $parcel, (string) $this->shopAmount($sized, $currency), $postcode);
            $offered = $this->shipping->options($query);
            $inCurrency = fn(?string $amount): ?string => $amount === null ? null : (string) $this->currencies->amount(Money::of($amount), $currency);
            $freeFrom = $free ? null : $inCurrency($this->shipping->freeFrom($query));
            $shipsFrom = $offered === [] ? $inCurrency($this->shipping->shipsFrom($query)) : null;
            $asItems = $this->tax->shippingTaxedAsItems() && !$import->applies() ? PriceShares::itemTaxShares($taxesOf) : null;
            // Free shipping makes the cheapest way to ship free, unless the coupon gives every one: the customer
            // would otherwise choose express, or abroad, at the shop's cost. Collecting from the shop is no way to
            // ship: a free pickup would leave every delivery charged.
            $cheapest = null;
            foreach ($offered as $option) {
                if ($option->collectAt === null && ($cheapest === null || Money::of($option->price)->compare(Money::of($cheapest->price)) < 0)) {
                    $cheapest = $option;
                }
            }
            $anyFree = $free;
            foreach ($offered as $option) {
                $free = $anyFree && ($discounts->freeShippingEveryRate() || $option->id === $cheapest?->id);
                $taxes = $option->taxable && !$free && !$goodsReverse && (!$import->applies() || $import->charges) ? $this->taxes($option->taxGroupId, $taxAt, $billing, $exempt, true, $import->applies()) : [];
                $price = $free ? Money::zero() : $this->currencies->amount(Money::of($option->price), $currency);
                $shares = null;
                if ($asItems !== null && $option->taxable && !$free) {
                    // Taxed as the items it carries: each share of the price at the rates of the items it is for.
                    // In the currency's cents, as the items are: each share rounded, and what rounding leaves of the
                    // rounded price goes to the largest share, so the shares still add up to the price.
                    $rounded = [];
                    $ratesOf = [];
                    $exact = Money::zero();
                    $largest = 0;
                    foreach (PriceShares::shares($price, array_column($asItems, 'weight')) as $index => $share) {
                        // The rates of the items' group that apply to shipping; none for untaxed items.
                        $shareTaxes = $goodsReverse || $asItems[$index]['taxes'] === [] ? [] : $this->taxes($asItems[$index]['group'], $taxAt, $billing, $exempt, true);
                        $paid = $inclusive ? Tax::asPaid($share, $shareTaxes, $this->shopTaxes($asItems[$index]['group'], true), $samePrice) : $share;
                        $exact = $exact->add($paid);
                        $rounded[] = $paid->rounded($decimals);
                        $ratesOf[] = $shareTaxes;
                        if ($asItems[$index]['weight']->compare($asItems[$largest]['weight']) > 0) {
                            $largest = $index;
                        }
                    }
                    $price = $exact->rounded($decimals);
                    $rounded[$largest] = $rounded[$largest]->add($price->subtract(array_reduce($rounded, static fn(Money $sum, Money $share): Money => $sum->add($share), Money::zero())));
                    $shares = array_map(static fn(Money $share, array $rates): array => [$share, $rates], $rounded, $ratesOf);
                    $taxes = [];
                } else {
                    if ($inclusive && $option->taxable && !$free) {
                        // A price including tax: what this customer pays for it, as the shop chose (Tax::asPaid()).
                        $price = Tax::asPaid($price, $taxes, $this->shopTaxes($option->taxGroupId, true), $samePrice);
                    }
                    // In the currency's cents: 5.00 with 20 % in it is 4.17 to a customer not charged it, not 4.1667.
                    $price = $price->rounded($decimals);
                }
                // Each rate's tax rounded once over the shares it applies to, as the bill charges it (Bill::billed()).
                $byRate = [];
                foreach ($shares ?? [[$price, $taxes]] as [$share, $shareTaxes]) {
                    foreach (Tax::parts($share, $shareTaxes, $inclusive) as $index => $part) {
                        $byRate[$shareTaxes[$index]->key] = ($byRate[$shareTaxes[$index]->key] ?? Money::zero())->add($part);
                    }
                }
                $tax = array_reduce($byRate, static fn(Money $sum, Money $part): Money => $sum->add($part->rounded($decimals)), Money::zero());
                $total = $inclusive ? $price : $price->add($tax);
                $view = new ShippingChoiceView($option->id, $option->carrier, $option->name, (string) $price, (string) $tax, (string) $total, $option->toPickupPoint, $option->collectAt);
                $options[] = $view;
                // The customer's choice when it is offered, otherwise the first rate, so the totals include shipping.
                if ($chosen === null || $option->id === $shippingOption) {
                    $chosen = $chosen !== null && $chosen['view']->id === $shippingOption ? $chosen : ['view' => $view, 'price' => $price, 'taxes' => $taxes, 'shares' => $shares, 'group' => $option->taxGroupId, 'free' => $free || !$option->taxable || $goodsReverse || ($import->applies() && !$import->charges), 'shop' => ['carrier' => $option->shopCarrier ?? $option->carrier, 'rate' => $option->shopName ?? $option->name, 'collect_at' => $option->shopCollectAt]];
                }
            }
            $free = $anyFree;
            if ($chosen !== null) {
                $bill->ship($chosen['price'], $chosen['taxes'], "{$chosen['view']->carrier} – {$chosen['view']->name}", $chosen['shares']);
                $asked[] = ['kind' => TaxRequestLine::SHIPPING, 'code' => $chosen['free'] ? 'none' : $chosen['group'], 'quantity' => 1, 'product' => null, 'sku' => null, 'tax_code' => null];
            }
        }
        $shopFees = [];
        $feeLines = [];
        if ($lines !== [] && !$itemsOnly) {
            // Fees are worked out in the shop's currency, then converted.
            $back = fn(Money $amount): string => (string) $this->currencies->back($amount, $currency);
            $this->charge($bill, new FeeQuery(
                array_map(static fn(array $line): FeeItem => new FeeItem($line['quote']->productId, $line['quote']->variationId, $line['quote']->name, $line['quantity'], $back($line['total']->subtract($line['discount']))), $lines),
                $back($bill->subtotal()->subtract($bill->discount())),
                $back($bill->shipping()),
                $chosen['view']->id ?? null,
                $payment,
                $needsShipping ? $shipping?->country : $billing?->country,
                $customerId,
                $settings->currency,
            ), $taxAt, $billing, $needsShipping ? $goodsReverse : $reverseCharge, $currency, $import, $decimals, $exempt, $asked, $shopFees, $charges, $feeLines);
            if (!$inclusive && !$allReverse) {
                $this->provideTaxes($bill, $asked, $taxAt ?? $billing, $currency, $customerId, $exemption, $orderId);
            }
        }

        return ['exemption' => $exemption === null ? null : ['reason' => $exemption->reason, 'certificate' => $exemption->certificate], 'import' => $import, 'lines' => $lines, 'bill' => $bill, 'needs_shipping' => $needsShipping, 'options' => $options, 'chosen' => $chosen['view'] ?? null, 'discounts' => $discounts, 'coupon_lines' => $couponLines, 'changes' => $adjusted, 'free_from' => $freeFrom, 'ships_from' => $shipsFrom, 'shop_shipping' => $chosen['shop'] ?? null, 'shop_fees' => $shopFees, 'fee_lines' => $feeLines, 'sized' => $sized];
    }

    /**
     * Adds the order's fees to the bill, then its charges, taxed as items of their tax group are.
     *
     * @param list<array{kind: string, code: int|string|null, quantity: int, product: ?int, sku: ?string, tax_code: ?string}> $asked
     *        what each charge is, for a tax service, in the bill's order; the fees are added to it
     * @param list<string> $shopFees the fees' labels in the shop's own language, in the bill's order
     * @param list<Charge> $charges
     * @param list<FeeLine> $feeLines what each of the bill's fees is, in its order
     */
    private function charge(Bill $bill, FeeQuery $query, ?TaxAddress $shipping, ?TaxAddress $billing, bool $reverseCharge, string $currency, ImportDecision $import, int $decimals, bool $exempt = false, array &$asked = [], array &$shopFees = [], array $charges = [], array &$feeLines = []): void
    {
        foreach ($this->fees->of($query) as $fee) {
            // Under an import scheme the fees go with the goods, as the shipping does: the destination's tax, or none.
            $taxed = $fee['taxable'] && !$reverseCharge && (!$import->applies() || $import->charges);
            $taxes = $taxed ? $this->taxes($fee['tax_group_id'], $shipping, $billing, $exempt, atShipping: $import->applies()) : [];
            $amount = $this->currencies->amount($fee['amount'], $currency);
            if ($bill->includesTax() && $fee['taxable']) {
                $amount = Tax::asPaid($amount, $taxes, $this->shopTaxes($fee['tax_group_id']), $this->tax->samePriceEverywhere())->rounded($decimals);
            }
            $bill->addFee($fee['label'], $amount, $taxes);
            $shopFees[] = $fee['shop_label'] ?? $fee['label'];
            $asked[] = ['kind' => TaxRequestLine::FEE, 'code' => $taxed ? $fee['tax_group_id'] : 'none', 'quantity' => 1, 'product' => null, 'sku' => null, 'tax_code' => null];
            $feeLines[] = ['type' => 'fee', 'quantity' => 1, 'unit' => $amount, 'source' => null, 'taxable' => $fee['taxable'], 'tax_group_id' => $fee['tax_group_id'], 'as_line' => $fee['as_line'] ?? false];
        }
        foreach ($charges as $charge) {
            if ($charge['quantity'] < 1) {
                continue;
            }
            $taxed = $charge['taxable'] && !$reverseCharge && (!$import->applies() || $import->charges);
            $taxes = $taxed ? $this->taxes($charge['tax_group_id'], $shipping, $billing, $exempt, atShipping: $import->applies()) : [];
            $given = $charge['given'] ?? false;
            // Given, it is what the customer pays already, in the order's currency (as an edited order keeps it).
            $unit = $given ? Money::of($charge['amount'])->rounded($decimals) : $this->currencies->amount(Money::of($charge['amount']), $currency);
            if (!$given && $bill->includesTax() && $charge['taxable']) {
                $unit = Tax::asPaid($unit, $taxes, $this->shopTaxes($charge['tax_group_id']), $this->tax->samePriceEverywhere());
            }
            $unit = $unit->rounded($decimals);
            if ($unit->compare(Money::zero()) <= 0) {
                continue;
            }
            $bill->addFee($charge['label'], $unit->times($charge['quantity']), $taxes);
            $shopFees[] = $charge['label'];
            $asked[] = ['kind' => TaxRequestLine::FEE, 'code' => $taxed ? $charge['tax_group_id'] : 'none', 'quantity' => $charge['quantity'], 'product' => null, 'sku' => null, 'tax_code' => null];
            $feeLines[] = ['type' => 'charge', 'quantity' => $charge['quantity'], 'unit' => $unit, 'source' => $charge['source'], 'taxable' => $charge['taxable'], 'tax_group_id' => $charge['tax_group_id'], 'as_line' => true, 'for_product_ids' => $charge['for_product_ids'] ?? []];
        }
    }

    /**
     * Asks the shop's tax service, when it chose one, for the tax of every charge; its taxes stand in for the
     * shop's rates. Without an answer the shop's rates stay, or checkout is refused, as the shop chose.
     *
     * @param list<array{kind: string, code: int|string|null, quantity: int, product: ?int, sku: ?string, tax_code: ?string}> $asked
     *
     * @throws TaxServiceUnavailable
     */
    private function provideTaxes(Bill $bill, array $asked, ?TaxAddress $to, string $currency, ?int $customerId, ?TaxExemptionView $exemption, ?int $orderId): void
    {
        if ($this->taxServices->active() === null || $to?->country === null) {
            return;
        }
        $charges = $bill->charges();
        $lines = [];
        $counts = [];
        foreach ($charges as $index => $charge) {
            $what = $asked[$index] ?? ['kind' => $charge->kind, 'code' => null, 'quantity' => 1, 'product' => null, 'sku' => null, 'tax_code' => null];
            $number = $counts[$charge->kind] = ($counts[$charge->kind] ?? -1) + 1;
            $lines[] = new TaxRequestLine(
                $charge->kind === BilledCharge::SHIPPING ? 'shipping' : "{$charge->kind}:{$number}",
                $charge->kind,
                $charge->label,
                $what['code'] === null ? null : (string) $what['code'],
                $what['quantity'],
                (string) $charge->amount,
                $what['product'],
                $what['sku'],
                // The product's own code, else its tax group's; none for what is not taxed.
                $what['tax_code'] ?? ($what['code'] === 'none' ? null : $this->taxServices->codeOf($what['code'] === null ? null : (int) $what['code'])),
            );
        }
        $shop = $this->settings->current();
        $result = $this->taxServices->quote(new TaxRequest(
            $currency,
            // The shop's day: a sale late in the evening is not tomorrow's, nor one after midnight yesterday's.
            $this->clock->now()->setTimezone($this->calendar->timezone())->format('Y-m-d'),
            new ExtensionTaxAddress($shop->shopCountry ?? '', $shop->shopState, $shop->shopPostcode ?? ''),
            new ExtensionTaxAddress($to->country, $to->state, $to->postcode ?? '', $to->city ?? '', $to->street ?? ''),
            $lines,
            $customerId,
            $exemption?->certificate,
            $exemption?->reason,
            $orderId,
        ));
        if ($result === null) {
            return;
        }
        // In the currency's cents: a service answering 1.2345 would leave a total no one can charge.
        $decimals = $this->currencies->decimals($currency);
        // What the shop does not tax ('none') stays untaxed, whatever the service answers for it.
        $bill->provide(array_map(static fn(TaxRequestLine $line): array => array_map(static fn(TaxLine $tax): ChargedTax => new ChargedTax(
            new Tax("{$tax->code}|{$tax->percent}", $tax->name, $tax->percent, false, new TaxJurisdiction($tax->country, $tax->region, $tax->level, $tax->code, $tax->jurisdiction), $tax->exempt),
            Money::of($tax->taxable),
            Money::of($tax->amount)->rounded($decimals),
        ), $line->taxCode === 'none' ? [] : $result->lines[$line->id] ?? []), $lines));
    }

    /**
     * The customer's exemption from tax that covered the order, as it keeps it.
     *
     * @param Priced $priced
     *
     * @return array{reason: string, certificate: string}|null
     */
    public function exemption(array $priced): ?array
    {
        return $priced['exemption'];
    }

    /**
     * The tax service that worked out the order's taxes, by its id; null when the shop's own rates did.
     *
     * @param Priced $priced
     */
    public function taxProvider(array $priced): ?string
    {
        return $priced['bill']->isProvided() ? $this->taxServices->active()?->id() : null;
    }

    /**
     * The coupons the order uses, as a quote shows them.
     *
     * @param Priced $priced
     *
     * @return list<OrderDiscountView>
     */
    public function discounts(array $priced): array
    {
        return array_map(static fn(AppliedCoupon $coupon): OrderDiscountView => new OrderDiscountView($coupon->code, $coupon->description, (string) Money::of($coupon->amount), $coupon->freeShipping, $coupon->automatic), $priced['discounts']->coupons);
    }

    /**
     * @param Priced $priced
     */
    public function shipping(array $priced, ?PickupPointView $pickupPoint = null): ?NewShipping
    {
        $chosen = $priced['chosen'];

        // Collected from the shop: the place, with when it is open, is where the order goes.
        $pickupPoint ??= $chosen?->collectAt;
        $label = $pickupPoint === null ? null : self::placeLabel($pickupPoint, $chosen?->collectAt !== null);
        // And in the shop's own words: its own place in them; a courier's point is the courier's.
        $shop = $priced['shop_shipping'];
        $shopPoint = $chosen?->collectAt !== null ? $shop['collect_at'] ?? null : null;

        return $chosen === null || !$priced['needs_shipping'] ? null : new NewShipping($chosen->id, $chosen->carrier, $chosen->name, $pickupPoint?->id, $label, $shop['carrier'] ?? null, $shop['rate'] ?? null, $shopPoint === null ? null : self::placeLabel($shopPoint, true));
    }

    /** A place's name and address, with when it is open for the shop's own places. */
    private static function placeLabel(PickupPointView $point, bool $shops): string
    {
        return $shops && $point->hours !== '' ? "{$point->label()} ({$point->hours})" : $point->label();
    }

    /**
     * The offices and lockers the chosen service delivers to near the address; none for a service to the door.
     *
     * @param Priced $priced
     *
     * @return list<PickupPointView>
     */
    public function pickupPoints(array $priced, ?string $country, ?string $postcode): array
    {
        $chosen = $priced['chosen'];

        return $chosen === null || !$chosen->toPickupPoint || $country === null ? [] : $this->shipping->pickupPoints($chosen->id, $country, $postcode, null);
    }

    /**
     * The point the customer chose, when the chosen service delivers to one; null for a service to the door.
     *
     * @param Priced $priced
     *
     * @throws CheckoutRuleViolation when the service needs a point and none, or an unknown one, was chosen
     */
    public function pickupPoint(array $priced, string $country, ?string $postcode, ?string $pointId): ?PickupPointView
    {
        if ($priced['chosen'] === null || !$priced['chosen']->toPickupPoint || !$priced['needs_shipping']) {
            return null;
        }
        foreach ($this->pickupPoints($priced, $country, $postcode) as $point) {
            if ($point->id === $pointId) {
                return $point;
            }
        }

        throw new CheckoutRuleViolation('Choose the office or locker to pick the order up from.', 'orillacart_pickup_point_required');
    }

    /**
     * @return list<Tax>
     */
    private function taxes(?int $groupId, ?TaxAddress $shipping, ?TaxAddress $billing, bool $exempt = false, bool $forShipping = false, bool $atShipping = false, bool $goods = true): array
    {
        return array_map(
            static fn(AppliedTaxView $tax): Tax => self::tax($tax, $exempt),
            $this->tax->taxes(new TaxQuery($groupId, $shipping, $billing, $forShipping, $atShipping, $goods))->taxes,
        );
    }

    /**
     * Whether an import scheme takes the order's goods: what they are worth before shipping and tax, in the shop's
     * currency (a price entered with tax without the shop's), and the dearest of them for limits per item.
     *
     * @param list<array{product_id: int, option_ids: list<int>, bundle_choices?: list<int>, quantity: int, unit_price?: ?string, given?: ?string, add_on?: array{type: string, label: string}|null, extra?: string, personalisation?: list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}>}> $items
     * @param array<int, ItemQuote> $quoted
     */
    private function importDecision(array $items, array $quoted, ?TaxAddress $shipping, string $currency, bool $inclusive): ImportDecision
    {
        $goods = Money::zero();
        $highest = Money::zero();
        $any = false;
        foreach ($items as $index => $line) {
            $quote = $quoted[$index];
            if ($quote->type !== 'physical') {
                continue;
            }
            $any = true;
            $unit = isset($line['unit_price']) ? $this->currencies->back(Money::of($line['unit_price']), $currency) : Money::of($quote->unitPrice);
            if ($inclusive && $quote->taxable) {
                $unit = Tax::without($unit, $this->shopTaxes($quote->taxGroupId));
            }
            $goods = $goods->add($unit->times($line['quantity']));
            $highest = $unit->compare($highest) > 0 ? $unit : $highest;
        }

        return $any ? $this->importSchemes->decide($shipping, $goods, $highest) : new ImportDecision();
    }

    public static function tax(AppliedTaxView $tax, bool $exempt = false): Tax
    {
        $place = $tax->jurisdiction;

        return new Tax(
            $tax->rateId === null ? "{$tax->name}|{$tax->percent}" : (string) $tax->rateId,
            $tax->name,
            $tax->percent,
            $tax->compound,
            $place === null ? null : new TaxJurisdiction($place->country, $place->region, $place->level, $place->code, $place->name),
            $exempt,
        );
    }

    /**
     * The rates a price including tax holds at the shop's own address.
     *
     * @return list<Tax>
     */
    private function shopTaxes(?int $groupId, bool $forShipping = false): array
    {
        return array_map(static fn(AppliedTaxView $tax): Tax => self::tax($tax), $this->tax->shopTaxes($groupId, $forShipping)->taxes);
    }

    /**
     * @return list<array{attribute: string, option: string}>
     */
    public static function options(ItemQuote $quote): array
    {
        return array_map(static fn(QuotedOption $option): array => ['attribute' => $option->attribute, 'option' => $option->name], $quote->options);
    }
}
