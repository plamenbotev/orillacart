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
use OrillaCart\Extension\FailsClosed;
use OrillaCart\Extension\Pricing\AdjustedLine;
use OrillaCart\Extension\Pricing\Adjustment;
use OrillaCart\Extension\Pricing\AdjustmentQuery;
use OrillaCart\Extension\Tax\TaxAddress as ExtensionTaxAddress;
use OrillaCart\Extension\Tax\TaxLine;
use OrillaCart\Extension\Tax\TaxRequest;
use OrillaCart\Extension\Tax\TaxRequestLine;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\Dto\TakenStock;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Inventory\Domain\InsufficientStock;
use OrillaCart\Ordering\Application\Dto\AdjustmentView;
use OrillaCart\Ordering\Application\Dto\LineTaxView;
use OrillaCart\Ordering\Application\Dto\NewOrderLine;
use OrillaCart\Ordering\Application\Dto\NewShipping;
use OrillaCart\Ordering\Application\Dto\OrderDiscountView;
use OrillaCart\Ordering\Domain\OrderLine;
use OrillaCart\Ordering\Domain\PriceAdjustment;
use OrillaCart\Settings\Application\Currencies;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Domain\TaxJurisdiction;
use OrillaCart\Shared\Extension\ExtensionFailures;
use OrillaCart\Shared\Extension\InstalledExtensions;
use OrillaCart\Shipping\Application\Dto\ParcelItem;
use OrillaCart\Shipping\Application\Dto\PickupPointView;
use OrillaCart\Shipping\Application\Dto\ShippingQuery;
use OrillaCart\Shipping\Application\ShippingQuotes;
use OrillaCart\Tax\Application\Dto\AppliedTaxView;
use OrillaCart\Tax\Application\Dto\ImportDecision;
use OrillaCart\Tax\Application\Dto\TaxAddress;
use OrillaCart\Tax\Application\Dto\TaxExemptionView;
use OrillaCart\Tax\Application\Dto\TaxQuery;
use OrillaCart\Tax\Application\ImportSchemes;
use OrillaCart\Tax\Application\TaxCalculator;
use OrillaCart\Tax\Application\TaxServices;
use OrillaCart\Tax\Application\TaxServiceUnavailable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Prices items as an order: catalog prices, taxes at the customer's address, shipping, fees and the bill; and turns
 * a priced order into order lines, taking their stock. Checkout and order editing price the same way.
 *
 * Coupons take money off the items before tax is charged, and free shipping makes every way to ship cost nothing.
 * A free gift that holds is added to the items, when it is for sale, and the order priced again with it.
 *
 * @phpstan-type PricedLine array{quote: ItemQuote, option_ids: list<int>, bundle_choices: list<int>, quantity: int, unit_price: string, total: Money, discount: Money, tax: Money, places: list<int>, gift: bool, personalisation: list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}>}
 * @phpstan-type Priced array{exemption: array{reason: string, certificate: string}|null, import: ImportDecision, lines: list<PricedLine>, bill: Bill, needs_shipping: bool, options: list<ShippingChoiceView>, chosen: ?ShippingChoiceView, discounts: AppliedDiscounts, coupon_lines: list<list<Money>>, changes: array<int, list<AdjustmentView>>, free_from: ?string, ships_from: ?string}
 */
final readonly class OrderPricing
{
    public function __construct(
        private ProductQuotes $quotes,
        private TaxCalculator $tax,
        private ShippingQuotes $shipping,
        private StockService $stock,
        private SettingsService $settings,
        private Discounts $discounts,
        private OrderFees $fees,
        private Currencies $currencies,
        private TaxServices $taxServices,
        private ClockInterface $clock,
        private InstalledExtensions $extensions,
        private LoggerInterface $logger,
        private ExtensionFailures $failures,
        private ImportSchemes $importSchemes,
    ) {}

    /**
     * Prices items with the customer's addresses: taxes, the shipping rates offered and the chosen one (or the
     * first), and the bill. An item may carry its own unit price, which the shop sets when it edits an order or
     * keeps from it.
     *
     * @param list<array{product_id: int, option_ids: list<int>, bundle_choices?: list<int>, quantity: int, unit_price?: ?string, gift?: bool, extra?: string, personalisation?: list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}>}> $items
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
     *
     * @return Priced
     *
     * @throws CheckoutRuleViolation for an item no longer sold, or not in that quantity
     */
    public function price(array $items, ?TaxAddress $shipping, ?TaxAddress $billing, ?string $shippingOption, ?string $postcode = null, array $codes = [], ?int $customerId = null, ?string $email = null, ?int $orderId = null, bool $reverseCharge = false, ?string $payment = null, ?string $currency = null, array $changes = [], ?array $automatic = null): array
    {
        $currency ??= $this->settings->current()->currency;
        $priced = $this->priceItems($items, $shipping, $billing, $shippingOption, $postcode, $codes, $customerId, $email, $orderId, $reverseCharge, $payment, $currency, $changes, $automatic);
        $gifts = $this->gifts($priced['discounts']->gifts, $customerId);

        return $gifts === [] ? $priced : $this->priceItems([...$items, ...$gifts], $shipping, $billing, $shippingOption, $postcode, $codes, $customerId, $email, $orderId, $reverseCharge, $payment, $currency, $changes, $automatic);
    }

    /**
     * The free gifts to add that are for sale in that quantity; a gift that is not is left out.
     *
     * @param list<array{productId: int, quantity: int}> $gifts
     *
     * @return list<array{product_id: int, option_ids: list<int>, quantity: int, gift: bool}>
     */
    private function gifts(array $gifts, ?int $customerId): array
    {
        $items = [];
        foreach ($gifts as $gift) {
            try {
                $quote = $this->quotes->quote(new QuoteRequest($gift['productId'], [], $gift['quantity'], $customerId));
            } catch (NotFound|RuleViolation) {
                continue;
            }
            if ($quote->forSale && ($quote->available === null || $quote->available >= $gift['quantity'])) {
                $items[] = ['product_id' => $gift['productId'], 'option_ids' => [], 'quantity' => $gift['quantity'], 'gift' => true];
            }
        }

        return $items;
    }

    /**
     * @param list<array{product_id: int, option_ids: list<int>, bundle_choices?: list<int>, quantity: int, unit_price?: ?string, gift?: bool, extra?: string, personalisation?: list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}>}> $items
     *                                    extra: what the answers to the product's forms add to each item; personalisation: those answers
     * @param list<array{kind: string, source: string, label: string, amount: string, line: ?int, reason: ?string, change: ?string}> $changes
     * @param list<string> $codes
     * @param list<string>|null $automatic
     *
     * @return Priced
     */
    private function priceItems(array $items, ?TaxAddress $shipping, ?TaxAddress $billing, ?string $shippingOption, ?string $postcode, array $codes, ?int $customerId, ?string $email, ?int $orderId, bool $reverseCharge, ?string $payment, string $currency, array $changes = [], ?array $automatic = null): array
    {
        $settings = $this->settings->current();
        $decimals = $this->currencies->decimals($currency);
        $inclusive = $this->tax->pricesIncludeTax();
        $samePrice = $this->tax->samePriceEverywhere();
        $bill = new Bill($decimals, $inclusive, $this->tax->roundsEachLine());
        // A customer's exemption covers the sale where it is taxed: the rates still apply, at nothing.
        $exemption = $reverseCharge ? null : $this->tax->exemption($customerId, $shipping, $billing);
        $exempt = $exemption !== null;
        /** @var list<array{kind: string, code: int|string|null, quantity: int, product: ?int, sku: ?string, tax_code: ?string}> $asked */
        $asked = [];
        $lines = [];
        $parcel = [];
        $taxesOf = [];
        // Prices by quantity count every line of the product: its variations and option choices together.
        $ofProduct = [];
        foreach ($items as $line) {
            $ofProduct[$line['product_id']] = ($ofProduct[$line['product_id']] ?? 0) + $line['quantity'];
        }
        $quoted = [];
        foreach ($items as $index => $line) {
            try {
                $quote = $this->quotes->quote(new QuoteRequest($line['product_id'], $line['option_ids'], $ofProduct[$line['product_id']], $customerId, $line['bundle_choices'] ?? [], $line['extra'] ?? '0'));
            } catch (NotFound|RuleViolation) {
                throw new CheckoutRuleViolation('An item is no longer sold. Review the items and try again.', 'orillacart_cart_changed');
            }
            if (!$quote->forSale || ($quote->available !== null && $quote->available < $line['quantity'])) {
                throw new CheckoutRuleViolation(\sprintf('%s is not available in that quantity. Review the items and try again.', $quote->name), 'orillacart_cart_changed');
            }
            $quoted[$index] = $quote;
        }
        $import = $reverseCharge ? new ImportDecision() : $this->importDecision($items, $quoted, $shipping, $currency, $inclusive);
        foreach ($items as $index => $line) {
            $quote = $quoted[$index];
            // Goods shipped under an import scheme: taxed at the destination when the shop charges it, otherwise not.
            $imported = $import->applies() && $quote->type === 'physical';
            // A bundle is taxed per item: its price shared out by what its items cost, each share at its item's rates.
            $parts = [];
            // A price given for the line (one the order kept, or the shop's own) is what this customer pays already.
            $given = isset($line['unit_price']);
            $unitPrice = $given ? Money::of($line['unit_price']) : $this->currencies->price(Money::of($quote->unitPrice), $currency);
            $units = self::shares($unitPrice, $quote->contents === [] ? [Money::of('1')] : array_map(static fn(QuotedBundleItem $item): Money => Money::of($item->price), $quote->contents));
            foreach ($quote->contents === [] ? [[$quote->taxable, $quote->taxGroupId, $quote->taxCode]] : array_map(static fn(QuotedBundleItem $item): array => [$item->taxable, $item->taxGroupId, $item->taxCode], $quote->contents) as $index => [$taxable, $groupId, $taxCode]) {
                $taxes = $taxable && !$reverseCharge && (!$imported || $import->charges) ? $this->taxes($groupId, $shipping, $billing, $exempt, atShipping: $imported) : [];
                $unit = $units[$index];
                if ($inclusive && $taxable && !$given) {
                    // A price including tax: what this customer pays for it, as the shop chose (Tax::asPaid()).
                    // In the currency's cents: 10.00 with 20 % in it is 8.33 without, not 8.3333.
                    $unit = Tax::asPaid($unit, $taxes, $this->shopTaxes($groupId), $samePrice)->rounded($decimals);
                }
                $parts[] = ['unit' => $unit, 'taxes' => $taxes, 'group' => $groupId, 'tax_code' => $taxCode];
            }
            $unitPrice = (string) array_reduce($parts, static fn(Money $sum, array $part): Money => $sum->add($part['unit']), Money::zero());
            $total = Money::of($unitPrice)->times($line['quantity']);
            $taxesOf[] = array_map(static fn(array $part): array => ['total' => $part['unit']->times($line['quantity']), 'taxes' => $part['taxes'], 'group' => $part['group'], 'tax_code' => $part['tax_code']], $parts);
            $lines[] = ['quote' => $quote, 'option_ids' => $line['option_ids'], 'bundle_choices' => $line['bundle_choices'] ?? [], 'quantity' => $line['quantity'], 'unit_price' => $unitPrice, 'total' => $total, 'discount' => Money::zero(), 'tax' => Money::zero(), 'places' => [], 'gift' => $line['gift'] ?? false, 'personalisation' => $line['personalisation'] ?? []];
            if ($quote->type === 'physical') {
                $parcel[] = new ParcelItem($line['quantity'], $quote->weight, $quote->length, $quote->width, $quote->height, $quote->shippingClassId);
            }
        }
        $discounts = $lines === [] ? new AppliedDiscounts() : $this->discounts->apply($codes, new DiscountRequest(
            array_map(static fn(array $line): DiscountItem => new DiscountItem($line['quote']->productId, $line['quantity'], $line['total']->toFixed(), $line['quote']->onSale, $line['quote']->categoryIds, $line['quote']->brandIds, $line['quote']->coupons), $lines),
            $currency,
            $shipping?->country,
            $customerId,
            $email,
            $orderId,
            $this->currencies->rate($currency),
            $automatic,
        ));
        $couponLines = self::roundedCoupons($discounts, \count($lines), $decimals);
        foreach ($lines as $index => $line) {
            $lines[$index]['discount'] = array_reduce($couponLines, static fn(Money $sum, array $taken): Money => $sum->add($taken[$index]), Money::zero());
        }
        $adjusted = $this->priceChanges($changes, $lines, $decimals, $currency, $customerId, $email, $orderId);
        $places = [];
        foreach ($lines as $index => $line) {
            foreach ($adjusted[$index] ?? [] as $change) {
                $lines[$index]['discount'] = $lines[$index]['discount']->subtract(Money::of($change->amount));
            }
            if ($lines[$index]['discount']->compare($lines[$index]['total']) > 0) {
                throw new CheckoutRuleViolation(\sprintf('The changes to the price of %s take off more than it costs.', $lines[$index]['quote']->name), 'orillacart_invalid_price_change');
            }
            $parts = $taxesOf[$index];
            $discountShares = self::shares($lines[$index]['discount'], array_map(static fn(array $part): Money => $part['total'], $parts));
            foreach ($parts as $part => ['total' => $partTotal, 'taxes' => $taxes, 'group' => $groupId, 'tax_code' => $taxCode]) {
                $places[$index][] = $bill->addItem($partTotal, $taxes, $discountShares[$part], $line['quote']->name);
                $asked[] = ['kind' => TaxRequestLine::ITEM, 'code' => $groupId, 'quantity' => $line['quantity'], 'product' => $line['quote']->productId, 'sku' => $line['quote']->sku, 'tax_code' => $taxCode];
            }
        }
        $lineTaxes = $bill->lineTaxes();
        foreach ($lines as $index => $line) {
            $lines[$index]['tax'] = array_reduce($places[$index] ?? [], static fn(Money $sum, int $place): Money => $sum->add($lineTaxes[$place]), Money::zero());
            $lines[$index]['places'] = $places[$index] ?? [];
        }
        $free = $discounts->freeShipping();

        $needsShipping = $parcel !== [];
        $options = [];
        $chosen = null;
        $freeFrom = null;
        $shipsFrom = null;
        if ($needsShipping) {
            // Rates are the shop's: they look at what the order is worth in its currency.
            $query = new ShippingQuery($shipping?->country, $shipping?->state, $parcel, (string) $this->currencies->back($bill->subtotal()->subtract($bill->discount()), $currency), $postcode);
            $offered = $this->shipping->options($query);
            $inCurrency = fn(?string $amount): ?string => $amount === null ? null : (string) $this->currencies->amount(Money::of($amount), $currency);
            $freeFrom = $free ? null : $inCurrency($this->shipping->freeFrom($query));
            $shipsFrom = $offered === [] ? $inCurrency($this->shipping->shipsFrom($query)) : null;
            $asItems = $this->tax->shippingTaxedAsItems() && !$import->applies() ? self::itemTaxShares($taxesOf) : null;
            foreach ($offered as $option) {
                $taxes = $option->taxable && !$free && !$reverseCharge && (!$import->applies() || $import->charges) ? $this->taxes($option->taxGroupId, $shipping, $billing, $exempt, true, $import->applies()) : [];
                $price = $free ? Money::zero() : $this->currencies->amount(Money::of($option->price), $currency);
                $shares = null;
                if ($asItems !== null && $option->taxable && !$free) {
                    // Taxed as the items it carries: each share of the price at the rates of the items it is for.
                    $shares = [];
                    foreach (self::shares($price, array_column($asItems, 'weight')) as $index => $share) {
                        // The rates of the items' group that apply to shipping; none for untaxed items.
                        $shareTaxes = $reverseCharge || $asItems[$index]['taxes'] === [] ? [] : $this->taxes($asItems[$index]['group'], $shipping, $billing, $exempt, true);
                        $shares[] = [$inclusive ? Tax::asPaid($share, $shareTaxes, $this->shopTaxes($asItems[$index]['group'], true), $samePrice) : $share, $shareTaxes];
                    }
                    $price = array_reduce($shares, static fn(Money $sum, array $share): Money => $sum->add($share[0]), Money::zero());
                    $taxes = [];
                } elseif ($inclusive && $option->taxable && !$free) {
                    $price = Tax::asPaid($price, $taxes, $this->shopTaxes($option->taxGroupId, true), $samePrice);
                }
                $tax = $shares === null
                    ? array_reduce(Tax::parts($price, $taxes, $inclusive), static fn(Money $sum, Money $part): Money => $sum->add($part->rounded($decimals)), Money::zero())
                    : array_reduce($shares, static fn(Money $sum, array $share): Money => array_reduce(Tax::parts($share[0], $share[1], $inclusive), static fn(Money $sum, Money $part): Money => $sum->add($part), $sum), Money::zero())->rounded($decimals);
                $total = $inclusive ? $price->rounded($decimals) : $price->rounded($decimals)->add($tax);
                $view = new ShippingChoiceView($option->id, $option->carrier, $option->name, (string) $price->rounded($decimals), (string) $tax, (string) $total, $option->toPickupPoint, $option->collectAt);
                $options[] = $view;
                // The customer's choice when it is offered, otherwise the first rate, so the totals include shipping.
                if ($chosen === null || $option->id === $shippingOption) {
                    $chosen = $chosen !== null && $chosen['view']->id === $shippingOption ? $chosen : ['view' => $view, 'price' => $price, 'taxes' => $taxes, 'shares' => $shares, 'group' => $option->taxGroupId, 'free' => $free || !$option->taxable];
                }
            }
            if ($chosen !== null) {
                $bill->ship($chosen['price'], $chosen['taxes'], "{$chosen['view']->carrier} – {$chosen['view']->name}", $chosen['shares']);
                $asked[] = ['kind' => TaxRequestLine::SHIPPING, 'code' => $chosen['free'] ? 'none' : $chosen['group'], 'quantity' => 1, 'product' => null, 'sku' => null, 'tax_code' => null];
            }
        }
        if ($lines !== []) {
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
            ), $shipping, $billing, $reverseCharge, $currency, $exempt, $asked);
            if (!$inclusive && !$reverseCharge) {
                $this->provideTaxes($bill, $asked, $shipping ?? $billing, $currency, $customerId, $exemption, $orderId);
            }
        }

        return ['exemption' => $exemption === null ? null : ['reason' => $exemption->reason, 'certificate' => $exemption->certificate], 'import' => $import, 'lines' => $lines, 'bill' => $bill, 'needs_shipping' => $needsShipping, 'options' => $options, 'chosen' => $chosen['view'] ?? null, 'discounts' => $discounts, 'coupon_lines' => $couponLines, 'changes' => $adjusted, 'free_from' => $freeFrom, 'ships_from' => $shipsFrom];
    }

    /**
     * Adds the order's fees to the bill, taxed as items of their tax group are.
     */
    /**
     * @param list<array{kind: string, code: int|string|null, quantity: int, product: ?int, sku: ?string, tax_code: ?string}> $asked
     *        what each charge is, for a tax service, in the bill's order; the fees are added to it
     */
    private function charge(Bill $bill, FeeQuery $query, ?TaxAddress $shipping, ?TaxAddress $billing, bool $reverseCharge, string $currency, bool $exempt = false, array &$asked = []): void
    {
        foreach ($this->fees->of($query) as $fee) {
            $taxes = $fee['taxable'] && !$reverseCharge ? $this->taxes($fee['tax_group_id'], $shipping, $billing, $exempt) : [];
            $amount = $this->currencies->amount($fee['amount'], $currency);
            if ($bill->includesTax() && $fee['taxable']) {
                $amount = Tax::asPaid($amount, $taxes, $this->shopTaxes($fee['tax_group_id']), $this->tax->samePriceEverywhere());
            }
            $bill->addFee($fee['label'], $amount, $taxes);
            $asked[] = ['kind' => TaxRequestLine::FEE, 'code' => $fee['taxable'] ? $fee['tax_group_id'] : 'none', 'quantity' => 1, 'product' => null, 'sku' => null, 'tax_code' => null];
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
            $this->clock->now()->format('Y-m-d'),
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
        $bill->provide(array_map(static fn(TaxRequestLine $line): array => array_map(static fn(TaxLine $tax): ChargedTax => new ChargedTax(
            new Tax("{$tax->code}|{$tax->percent}", $tax->name, $tax->percent, false, new TaxJurisdiction($tax->country, $tax->region, $tax->level, $tax->code, $tax->jurisdiction), $tax->exempt),
            Money::of($tax->taxable),
            Money::of($tax->amount),
        ), $result->lines[$line->id] ?? []), $lines));
    }

    /**
     * Order lines for the priced items, taking their stock. Run it inside the transaction that saves the order.
     *
     * @param Priced $priced
     *
     * @return list<NewOrderLine>
     *
     * @throws CheckoutRuleViolation when stock ran out since the items were priced
     */
    public function lines(array $priced): array
    {
        $this->stock->lock(array_merge(...array_map(static fn(array $line): array => self::stockRefs($line['quote']), $priced['lines'])));

        $items = $priced['bill']->charges(BilledCharge::ITEM);
        $lines = [];
        foreach ($priced['lines'] as $index => $line) {
            $taken = $this->take($line['quote'], $line['quantity']);
            $charged = array_merge([], ...array_map(static fn(int $place): array => isset($items[$place]) ? $items[$place]->taxes : [], $line['places']));
            $lines[] = $this->line($line, $taken, self::lineTaxes($charged), [...self::couponAdjustments($priced['discounts'], $priced['coupon_lines'], $index), ...$priced['changes'][$index] ?? []]);
        }

        return $lines;
    }

    /**
     * The order's shipping and fees as lines, each with its taxes: what checkout charges besides the items. A
     * coupon that made shipping free, or took nothing off any item, is kept on the shipping line or the first
     * item at nothing, so the order still names it.
     *
     * @param Priced $priced
     *
     * @return list<NewOrderLine>
     */
    public function charges(array $priced): array
    {
        $bill = $priced['bill'];
        $lines = [];
        $shipping = $this->shipping($priced) === null ? [] : $bill->charges(BilledCharge::SHIPPING);
        foreach ($shipping as $charge) {
            $free = array_values(array_filter($priced['discounts']->coupons, static fn(AppliedCoupon $coupon): bool => $coupon->freeShipping));
            $lines[] = self::chargeLine($charge, OrderLine::SHIPPING, array_map(
                static fn(AppliedCoupon $coupon): AdjustmentView => new AdjustmentView($coupon->automatic ? PriceAdjustment::AUTOMATIC : PriceAdjustment::COUPON, $coupon->code, $coupon->description, '0', PriceAdjustment::ORDER, true),
                $free,
            ));
        }
        foreach ($bill->charges(BilledCharge::FEE) as $charge) {
            $lines[] = self::chargeLine($charge, OrderLine::FEE, []);
        }

        return $lines;
    }

    /**
     * The order's lines: the items, taking their stock, then the shipping and fees. Run it inside the transaction
     * that saves the order.
     *
     * @param Priced $priced
     *
     * @return list<NewOrderLine>
     *
     * @throws CheckoutRuleViolation when stock ran out since the items were priced
     */
    public function orderLines(array $priced): array
    {
        return [...$this->lines($priced), ...$this->charges($priced)];
    }

    /**
     * @param list<AdjustmentView> $adjustments
     */
    private static function chargeLine(BilledCharge $charge, string $kind, array $adjustments): NewOrderLine
    {
        return new NewOrderLine(null, null, [], [], $charge->label, null, 1, (string) $charge->amount, (string) $charge->amount, self::lineTaxes($charge->taxes), false, [], adjustments: $adjustments, kind: $kind);
    }

    /**
     * A line's taxes, one per rate: the shares of its charges (a bundle's parts) added up.
     *
     * @param list<ChargedTax> $charged
     *
     * @return list<LineTaxView>
     */
    private static function lineTaxes(array $charged): array
    {
        $taxes = [];
        foreach ($charged as $tax) {
            $key = $tax->tax->key;
            $place = $tax->tax->jurisdiction ?? new TaxJurisdiction('', null, TaxJurisdiction::COUNTRY, $tax->tax->name, $tax->tax->name);
            $before = $taxes[$key] ?? null;
            $taxes[$key] = new LineTaxView(
                $tax->tax->name,
                $tax->tax->percent,
                $place->country,
                $place->region,
                $place->level,
                $place->code,
                $place->name,
                (string) Money::of($before->taxable ?? '0')->add($tax->taxable),
                (string) Money::of($before->amount ?? '0')->add($tax->amount),
                $tax->tax->compound,
                exempt: $tax->tax->exempt,
            );
        }

        return array_values(array_filter($taxes, static fn(LineTaxView $tax): bool => !Money::of($tax->amount)->isZero() || !Money::of($tax->taxable)->isZero()));
    }

    /**
     * What each coupon took off an item, as its adjustments; a coupon that took nothing off any item and does not
     * make shipping free is kept on the first item at nothing, so the order still names it.
     *
     * @param list<list<Money>> $couponLines what each coupon takes off each item, in the currency's decimals
     *
     * @return list<AdjustmentView>
     */
    private static function couponAdjustments(AppliedDiscounts $discounts, array $couponLines, int $index): array
    {
        $adjustments = [];
        foreach ($discounts->coupons as $number => $coupon) {
            $amount = $couponLines[$number][$index] ?? Money::zero();
            $named = $index === 0 && !$coupon->freeShipping && array_filter($couponLines[$number] ?? [], static fn(Money $taken): bool => !$taken->isZero()) === [];
            if ($amount->isZero() && !$named) {
                continue;
            }
            $adjustments[] = new AdjustmentView($coupon->automatic ? PriceAdjustment::AUTOMATIC : PriceAdjustment::COUPON, $coupon->code, $coupon->description, (string) Money::zero()->subtract($amount), PriceAdjustment::ORDER, $coupon->freeShipping);
        }

        return $adjustments;
    }

    /**
     * @param PricedLine           $line
     * @param list<TakenStock>     $taken
     * @param list<LineTaxView>    $taxes
     * @param list<AdjustmentView> $adjustments
     */
    private function line(array $line, array $taken, array $taxes, array $adjustments): NewOrderLine
    {
        // An item owes as many as its scarcest part: the product, or one of its options.
        $owed = [];
        foreach ($taken as $part) {
            $owed[$part->item] = ($owed[$part->item] ?? 0) + $part->owed;
        }
        $backordered = $owed === [] ? 0 : max($owed);

        return new NewOrderLine(
            $line['quote']->productId,
            $line['quote']->variationId,
            // The options asked for: a variation's, or the chosen options of a product without variations.
            $line['option_ids'],
            self::options($line['quote']),
            $line['quote']->name,
            $line['quote']->sku,
            $line['quantity'],
            $line['unit_price'],
            (string) $line['total'],
            $taxes,
            $line['quote']->type === 'physical',
            $taken,
            $line['quote']->downloads,
            $line['quote']->downloadLimit,
            $line['quote']->downloadExpiryDays,
            $backordered,
            $backordered > 0 && $line['quote']->backorders === 'notify',
            $adjustments,
            array_map(static fn(QuotedBundleItem $item): array => ['name' => $item->name, 'quantity' => $item->quantity], $line['quote']->contents),
            $line['quote']->contents === [] ? [] : $line['bundle_choices'],
            $line['personalisation'],
        );
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
        $label = $pickupPoint === null ? null : ($chosen?->collectAt !== null && $pickupPoint->hours !== '' ? "{$pickupPoint->label()} ({$pickupPoint->hours})" : $pickupPoint->label());

        return $chosen === null || !$priced['needs_shipping'] ? null : new NewShipping($chosen->id, $chosen->carrier, $chosen->name, $pickupPoint?->id, $label);
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
    private function taxes(?int $groupId, ?TaxAddress $shipping, ?TaxAddress $billing, bool $exempt = false, bool $forShipping = false, bool $atShipping = false): array
    {
        return array_map(
            static fn(AppliedTaxView $tax): Tax => self::tax($tax, $exempt),
            $this->tax->taxes(new TaxQuery($groupId, $shipping, $billing, $forShipping, $atShipping))->taxes,
        );
    }

    /**
     * Whether an import scheme takes the order's goods: what they are worth before shipping and tax, in the shop's
     * currency (a price entered with tax without the shop's), and the dearest of them for limits per item.
     *
     * @param list<array{product_id: int, option_ids: list<int>, bundle_choices?: list<int>, quantity: int, unit_price?: ?string, gift?: bool, extra?: string, personalisation?: list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}>}> $items
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
     * The changes to the items' prices besides coupons, by item: the shop's and add-ons' given, and what the
     * add-ons' order adjusters make now. One for the whole order is spread over the items by what they cost after
     * the coupons, rounded to the currency so they add up to it.
     *
     * @param list<array{kind: string, source: string, label: string, amount: string, line: ?int, reason: ?string, change: ?string}> $changes
     * @param list<PricedLine> $lines
     *
     * @return array<int, list<AdjustmentView>>
     *
     * @throws CheckoutRuleViolation for a change to a line the order does not have, or an adjuster that failed closed
     */
    private function priceChanges(array $changes, array $lines, int $decimals, string $currency, ?int $customerId, ?string $email, ?int $orderId): array
    {
        if ($lines === []) {
            return [];
        }
        $nets = array_map(static fn(array $line): Money => $line['total']->subtract($line['discount']), $lines);
        foreach ($this->extensions->orderAdjusters() as $adjuster) {
            $query = new AdjustmentQuery(
                array_map(static fn(int $index, array $line): AdjustedLine => new AdjustedLine($index, $line['quote']->productId, $line['quote']->variationId, $line['quote']->name, $line['quantity'], (string) $nets[$index]), array_keys($lines), $lines),
                $currency,
                $customerId,
                $email,
                $orderId,
            );
            try {
                $made = $adjuster->adjust($query);
            } catch (\Throwable $e) {
                $this->logger->error('The order adjuster {adjuster} failed: {message}', ['adjuster' => $adjuster->id(), 'message' => $e->getMessage(), 'exception' => $e]);
                if ($adjuster instanceof FailsClosed) {
                    throw new CheckoutRuleViolation('The prices could not be worked out just now. Try again in a moment.', 'orillacart_adjuster_failed');
                }
                $this->failures->record(\sprintf('The order adjuster %s failed; the order was priced without it.', $adjuster->id()), false);
                continue;
            }
            foreach ($made as $adjustment) {
                if (!$adjustment instanceof Adjustment || preg_match('/^-?\d{1,11}(\.\d{1,4})?$/', $adjustment->amount) !== 1 || ($adjustment->line !== null && !isset($lines[$adjustment->line])) || trim($adjustment->label) === '') {
                    $this->logger->error('The order adjuster {adjuster} answered an adjustment that cannot be; it is left out.', ['adjuster' => $adjuster->id()]);
                    continue;
                }
                $changes[] = ['kind' => PriceAdjustment::ADJUSTER, 'source' => $adjuster->id(), 'label' => trim($adjustment->label), 'amount' => $adjustment->amount, 'line' => $adjustment->line, 'reason' => trim($adjustment->reason) === '' ? null : trim($adjustment->reason), 'change' => null];
            }
        }
        $adjusted = [];
        foreach ($changes as $change) {
            $amount = Money::of($change['amount']);
            $key = $change['change'] ?? PriceAdjustment::newChange();
            if ($change['line'] !== null) {
                if (!isset($lines[$change['line']])) {
                    throw new CheckoutRuleViolation('The order has no such line to change the price of.', 'orillacart_invalid_price_change');
                }
                $adjusted[$change['line']][] = new AdjustmentView($change['kind'], $change['source'], $change['label'], (string) $amount->rounded($decimals), PriceAdjustment::LINE, false, $change['reason'], change: $key);
                continue;
            }
            foreach (self::spread($amount, $nets, $decimals) as $index => $share) {
                if (!$share->isZero()) {
                    $adjusted[$index][] = new AdjustmentView($change['kind'], $change['source'], $change['label'], (string) $share, PriceAdjustment::ORDER, false, $change['reason'], change: $key);
                }
            }
        }

        return $adjusted;
    }

    /**
     * An amount, above or below zero, shared over weights in the currency's decimals: rounded once, the cents
     * rounding leaves to the shares that lost the most, so the shares add up to it.
     *
     * @param list<Money> $weights
     *
     * @return list<Money>
     */
    private static function spread(Money $amount, array $weights, int $decimals): array
    {
        $negative = $amount->isNegative();
        $whole = ($negative ? Money::zero()->subtract($amount) : $amount)->rounded($decimals);
        $exact = self::shares($whole, $weights === [] ? [Money::of('1')] : $weights);
        $step = 10 ** (Money::DECIMALS - $decimals);
        $floors = array_map(static fn(Money $part): int => intdiv($part->tenThousandths, $step) * $step, $exact);
        $order = array_keys($exact);
        usort($order, static fn(int $a, int $b): int => [$exact[$b]->tenThousandths - $floors[$b], $a] <=> [$exact[$a]->tenThousandths - $floors[$a], $b]);
        for ($left = intdiv($whole->tenThousandths - array_sum($floors), $step), $i = 0; $left > 0 && $order !== []; --$left, ++$i) {
            $floors[$order[$i % \count($order)]] += $step;
        }

        return array_values(array_map(static fn(int $share): Money => Money::fromTenThousandths($negative ? -$share : $share), $floors));
    }

    /**
     * What each coupon takes off each item, in the currency's decimals: its whole amount rounded, shared over the
     * items by what it takes off each, the cents rounding leaves to the items that lost the most, so an order's
     * lines add up to it.
     *
     * @return list<list<Money>> by coupon, then by item
     */
    private static function roundedCoupons(AppliedDiscounts $discounts, int $items, int $decimals): array
    {
        $rounded = [];
        $step = 10 ** (Money::DECIMALS - $decimals);
        foreach ($discounts->coupons as $coupon) {
            $exact = [];
            for ($index = 0; $index < $items; ++$index) {
                $exact[] = Money::of($coupon->lines[$index] ?? '0')->tenThousandths;
            }
            $whole = Money::fromTenThousandths(array_sum($exact))->rounded($decimals)->tenThousandths;
            $floors = array_map(static fn(int $part): int => intdiv($part, $step) * $step, $exact);
            $order = array_keys($exact);
            usort($order, static fn(int $a, int $b): int => [$exact[$b] - $floors[$b], $a] <=> [$exact[$a] - $floors[$a], $b]);
            for ($left = intdiv($whole - array_sum($floors), $step), $i = 0; $left > 0 && $order !== []; --$left, ++$i) {
                $floors[$order[$i % \count($order)]] += $step;
            }
            $rounded[] = array_values(array_map(Money::fromTenThousandths(...), $floors));
        }

        return $rounded;
    }

    /**
     * An amount shared out in proportion to weights, exactly: what proportions leave over goes to the largest.
     * All of it to the first when the weights add up to nothing.
     *
     * @param non-empty-list<Money> $weights
     *
     * @return non-empty-list<Money> one share per weight, adding up to the amount
     */
    private static function shares(Money $amount, array $weights): array
    {
        $whole = array_reduce($weights, static fn(Money $sum, Money $weight): Money => $sum->add($weight), Money::zero());
        $shares = [];
        $left = $amount;
        $largest = 0;
        foreach ($weights as $index => $weight) {
            $share = $whole->isZero() ? ($index === 0 ? $amount : Money::zero()) : $amount->proportion($weight, $whole);
            $shares[] = $share;
            $left = $left->subtract($share);
            if ($weight->compare($weights[$largest]) > 0) {
                $largest = $index;
            }
        }
        $shares[$largest] = $shares[$largest]->add($left);

        return array_values($shares);
    }

    /**
     * The items' rates, for shipping taxed as the items it carries: each set of rates with what the items at it cost,
     * untaxed items too (their share of shipping is untaxed); the first item's group stands for the set's.
     *
     * @param list<list<array{total: Money, taxes: list<Tax>, group: ?int, tax_code: ?string}>> $taxesOf
     *
     * @return non-empty-list<array{weight: Money, taxes: list<Tax>, group: ?int}>
     */
    private static function itemTaxShares(array $taxesOf): array
    {
        $sets = [];
        foreach ($taxesOf as $parts) {
            foreach ($parts as $part) {
                $key = implode('|', array_map(static fn(Tax $tax): string => $tax->key . ($tax->exempt ? '!' : ''), $part['taxes']));
                $sets[$key] ??= ['weight' => Money::zero(), 'taxes' => $part['taxes'], 'group' => $part['group']];
                $sets[$key]['weight'] = $sets[$key]['weight']->add($part['total']);
            }
        }

        return $sets === [] ? [['weight' => Money::of('1'), 'taxes' => [], 'group' => null]] : array_values($sets);
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
     * The stock items an item takes from: a bundle's items, or the variation, or the product and its options.
     *
     * @return list<StockItemRef>
     */
    private static function stockRefs(ItemQuote $quote): array
    {
        if ($quote->contents !== []) {
            $refs = [];
            foreach ($quote->contents as $item) {
                if ($item->countsStock) {
                    $refs[] = $item->variationId !== null ? StockItemRef::variation($item->variationId) : StockItemRef::product($item->productId);
                }
            }

            return $refs;
        }

        return $quote->variationId !== null
            ? [StockItemRef::variation($quote->variationId)]
            : [StockItemRef::product($quote->productId), ...array_map(static fn(QuotedOption $option): StockItemRef => StockItemRef::option($option->id), $quote->options)];
    }

    /**
     * @return list<TakenStock>
     */
    private function take(ItemQuote $quote, int $quantity): array
    {
        if ($quote->contents !== []) {
            // A bundle takes each item's stock; all in the order's transaction, so all or none.
            $taken = [];
            foreach ($quote->contents as $item) {
                if (!$item->countsStock) {
                    continue;
                }
                $refs = [$item->variationId !== null ? StockItemRef::variation($item->variationId) : StockItemRef::product($item->productId)];
                try {
                    array_push($taken, ...$this->stock->take($refs, $item->quantity * $quantity, $item->backorders !== 'no', $item->backorderLimit));
                } catch (InsufficientStock) {
                    throw new CheckoutRuleViolation(\sprintf('%s sold out in the meantime. Review the items and try again.', $quote->name), 'orillacart_out_of_stock');
                }
            }

            return $taken;
        }
        $refs = self::stockRefs($quote);
        // Stock that does not limit the item takes nothing; items always available or never sold were checked above.
        // Backorders lift the limit, but the stock is still taken and the shortfall owed.
        try {
            return $quote->available === null && $quote->inStock === null ? [] : $this->stock->take($refs, $quantity, $quote->backorders !== 'no', $quote->backorderLimit);
        } catch (InsufficientStock) {
            throw new CheckoutRuleViolation(\sprintf('%s sold out in the meantime. Review the items and try again.', $quote->name), 'orillacart_out_of_stock');
        }
    }

    /**
     * @return list<array{attribute: string, option: string}>
     */
    public static function options(ItemQuote $quote): array
    {
        return array_map(static fn(QuotedOption $option): array => ['attribute' => $option->attribute, 'option' => $option->name], $quote->options);
    }
}
