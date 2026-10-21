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
use OrillaCart\Checkout\Domain\BilledTax;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Checkout\Domain\Tax;
use OrillaCart\Coupons\Application\Discounts;
use OrillaCart\Coupons\Application\Dto\AppliedCoupon;
use OrillaCart\Coupons\Application\Dto\AppliedDiscounts;
use OrillaCart\Coupons\Application\Dto\DiscountItem;
use OrillaCart\Coupons\Application\Dto\DiscountRequest;
use OrillaCart\Extension\Checkout\FeeItem;
use OrillaCart\Extension\Checkout\FeeQuery;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\Dto\TakenStock;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Inventory\Domain\InsufficientStock;
use OrillaCart\Ordering\Application\Dto\NewOrderFee;
use OrillaCart\Ordering\Application\Dto\NewOrderLine;
use OrillaCart\Ordering\Application\Dto\NewShipping;
use OrillaCart\Ordering\Application\Dto\OrderDiscountView;
use OrillaCart\Ordering\Application\Dto\TaxLineView;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shipping\Application\Dto\ParcelItem;
use OrillaCart\Shipping\Application\Dto\PickupPointView;
use OrillaCart\Shipping\Application\Dto\ShippingQuery;
use OrillaCart\Shipping\Application\ShippingQuotes;
use OrillaCart\Tax\Application\Dto\AppliedTaxView;
use OrillaCart\Tax\Application\Dto\TaxAddress;
use OrillaCart\Tax\Application\Dto\TaxQuery;
use OrillaCart\Tax\Application\TaxCalculator;

/**
 * Prices items as an order: catalog prices, taxes at the customer's address, shipping, fees and the bill; and turns
 * a priced order into order lines, taking their stock. Checkout and order editing price the same way.
 *
 * Coupons take money off the items before tax is charged, and free shipping makes every way to ship cost nothing.
 * A free gift that holds is added to the items, when it is for sale, and the order priced again with it.
 *
 * @phpstan-type PricedLine array{quote: ItemQuote, option_ids: list<int>, bundle_choices: list<int>, quantity: int, unit_price: string, total: Money, discount: Money, tax: Money, gift: bool}
 * @phpstan-type Priced array{lines: list<PricedLine>, bill: Bill, needs_shipping: bool, options: list<ShippingChoiceView>, chosen: ?ShippingChoiceView, discounts: AppliedDiscounts, free_from: ?string, ships_from: ?string}
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
    ) {}

    /**
     * Prices items with the customer's addresses: taxes, the shipping rates offered and the chosen one (or the
     * first), and the bill. An item may carry its own unit price, which the shop sets when it edits an order.
     *
     * @param list<array{product_id: int, option_ids: list<int>, bundle_choices?: list<int>, quantity: int, unit_price?: ?string, gift?: bool}> $items
     * @param string|null  $postcode   the shipping address's postcode, for rates that ship to some postcodes only
     * @param list<string> $codes      coupon codes; those that do not hold are left out, with the reason
     * @param string|null  $email      the customer's, for limits per customer, once known
     * @param int|null     $orderId    the order being priced again, when the shop edits it
     * @param bool         $reverseCharge a business in another EU country buys without VAT
     * @param string|null  $payment    the way to pay chosen, for its fee
     *
     * @return Priced
     *
     * @throws CheckoutRuleViolation for an item no longer sold, or not in that quantity
     */
    public function price(array $items, ?TaxAddress $shipping, ?TaxAddress $billing, ?string $shippingOption, ?string $postcode = null, array $codes = [], ?int $customerId = null, ?string $email = null, ?int $orderId = null, bool $reverseCharge = false, ?string $payment = null): array
    {
        $priced = $this->priceItems($items, $shipping, $billing, $shippingOption, $postcode, $codes, $customerId, $email, $orderId, $reverseCharge, $payment);
        $gifts = $this->gifts($priced['discounts']->gifts, $customerId);

        return $gifts === [] ? $priced : $this->priceItems([...$items, ...$gifts], $shipping, $billing, $shippingOption, $postcode, $codes, $customerId, $email, $orderId, $reverseCharge, $payment);
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
     * @param list<array{product_id: int, option_ids: list<int>, bundle_choices?: list<int>, quantity: int, unit_price?: ?string, gift?: bool}> $items
     * @param list<string> $codes
     *
     * @return Priced
     */
    private function priceItems(array $items, ?TaxAddress $shipping, ?TaxAddress $billing, ?string $shippingOption, ?string $postcode, array $codes, ?int $customerId, ?string $email, ?int $orderId, bool $reverseCharge, ?string $payment): array
    {
        $settings = $this->settings->current();
        $decimals = $settings->priceDecimals;
        $inclusive = $this->tax->pricesIncludeTax();
        $bill = new Bill($decimals, $inclusive, $this->tax->roundsEachLine());
        $lines = [];
        $parcel = [];
        $taxesOf = [];
        // Prices by quantity count every line of the product: its variations and option choices together.
        $ofProduct = [];
        foreach ($items as $line) {
            $ofProduct[$line['product_id']] = ($ofProduct[$line['product_id']] ?? 0) + $line['quantity'];
        }
        foreach ($items as $line) {
            try {
                $quote = $this->quotes->quote(new QuoteRequest($line['product_id'], $line['option_ids'], $ofProduct[$line['product_id']], $customerId, $line['bundle_choices'] ?? []));
            } catch (NotFound|RuleViolation) {
                throw new CheckoutRuleViolation('An item is no longer sold. Review the items and try again.', 'orillacart_cart_changed');
            }
            if (!$quote->forSale || ($quote->available !== null && $quote->available < $line['quantity'])) {
                throw new CheckoutRuleViolation(\sprintf('%s is not available in that quantity. Review the items and try again.', $quote->name), 'orillacart_cart_changed');
            }
            // A bundle is taxed per item: its price shared out by what its items cost, each share at its item's rates.
            $parts = [];
            $units = self::shares(Money::of($line['unit_price'] ?? $quote->unitPrice), $quote->contents === [] ? [Money::of('1')] : array_map(static fn(QuotedBundleItem $item): Money => Money::of($item->price), $quote->contents));
            foreach ($quote->contents === [] ? [[$quote->taxable, $quote->taxGroupId]] : array_map(static fn(QuotedBundleItem $item): array => [$item->taxable, $item->taxGroupId], $quote->contents) as $index => [$taxable, $groupId]) {
                $taxes = $taxable && !$reverseCharge ? $this->taxes($groupId, $shipping, $billing) : [];
                $unit = $units[$index];
                if ($inclusive && $taxable && $taxes === []) {
                    // A price including tax, for a customer who owes none (an export): the shop's own tax comes off.
                    $unit = Tax::without($unit, $this->shopTaxes($groupId));
                }
                $parts[] = ['unit' => $unit, 'taxes' => $taxes];
            }
            $unitPrice = (string) array_reduce($parts, static fn(Money $sum, array $part): Money => $sum->add($part['unit']), Money::zero());
            $total = Money::of($unitPrice)->times($line['quantity']);
            $taxesOf[] = array_map(static fn(array $part): array => ['total' => $part['unit']->times($line['quantity']), 'taxes' => $part['taxes']], $parts);
            $lines[] = ['quote' => $quote, 'option_ids' => $line['option_ids'], 'bundle_choices' => $line['bundle_choices'] ?? [], 'quantity' => $line['quantity'], 'unit_price' => $unitPrice, 'total' => $total, 'discount' => Money::zero(), 'tax' => Money::zero(), 'gift' => $line['gift'] ?? false];
            if ($quote->type === 'physical') {
                $parcel[] = new ParcelItem($line['quantity'], $quote->weight, $quote->length, $quote->width, $quote->height, $quote->shippingClassId);
            }
        }
        $discounts = $lines === [] ? new AppliedDiscounts() : $this->discounts->apply($codes, new DiscountRequest(
            array_map(static fn(array $line): DiscountItem => new DiscountItem($line['quote']->productId, $line['quantity'], $line['total']->toFixed(), $line['quote']->onSale, $line['quote']->categoryIds, $line['quote']->brandIds), $lines),
            $settings->currency,
            $shipping?->country,
            $customerId,
            $email,
            $orderId,
        ));
        $places = [];
        foreach ($lines as $index => $line) {
            $lines[$index]['discount'] = $discounts->forLine($index);
            $parts = $taxesOf[$index];
            $discountShares = self::shares($lines[$index]['discount'], array_map(static fn(array $part): Money => $part['total'], $parts));
            foreach ($parts as $part => ['total' => $partTotal, 'taxes' => $taxes]) {
                $places[$index][] = $bill->addItem($partTotal, $taxes, $discountShares[$part]);
            }
        }
        $lineTaxes = $bill->lineTaxes();
        foreach ($lines as $index => $line) {
            $lines[$index]['tax'] = array_reduce($places[$index] ?? [], static fn(Money $sum, int $place): Money => $sum->add($lineTaxes[$place]), Money::zero());
        }
        $free = $discounts->freeShipping();

        $needsShipping = $parcel !== [];
        $options = [];
        $chosen = null;
        $freeFrom = null;
        $shipsFrom = null;
        if ($needsShipping) {
            $query = new ShippingQuery($shipping?->country, $shipping?->state, $parcel, (string) $bill->subtotal()->subtract($bill->discount()), $postcode);
            $offered = $this->shipping->options($query);
            $freeFrom = $free ? null : $this->shipping->freeFrom($query);
            $shipsFrom = $offered === [] ? $this->shipping->shipsFrom($query) : null;
            foreach ($offered as $option) {
                $taxes = $option->taxable && !$free && !$reverseCharge ? $this->taxes($option->taxGroupId, $shipping, $billing) : [];
                $price = $free ? Money::zero() : Money::of($option->price);
                if ($inclusive && $option->taxable && $taxes === [] && !$free) {
                    $price = Tax::without($price, $this->shopTaxes($option->taxGroupId));
                }
                $tax = array_reduce(Tax::parts($price, $taxes, $inclusive), static fn(Money $sum, Money $part): Money => $sum->add($part->rounded($decimals)), Money::zero());
                $total = $inclusive ? $price->rounded($decimals) : $price->rounded($decimals)->add($tax);
                $view = new ShippingChoiceView($option->id, $option->carrier, $option->name, (string) $price->rounded($decimals), (string) $tax, (string) $total, $option->toPickupPoint, $option->collectAt);
                $options[] = $view;
                // The customer's choice when it is offered, otherwise the first rate, so the totals include shipping.
                if ($chosen === null || $option->id === $shippingOption) {
                    $chosen = $chosen !== null && $chosen['view']->id === $shippingOption ? $chosen : ['view' => $view, 'price' => $price, 'taxes' => $taxes];
                }
            }
            if ($chosen !== null) {
                $bill->ship($chosen['price'], $chosen['taxes']);
            }
        }
        if ($lines !== []) {
            $this->charge($bill, new FeeQuery(
                array_map(static fn(array $line): FeeItem => new FeeItem($line['quote']->productId, $line['quote']->variationId, $line['quote']->name, $line['quantity'], (string) $line['total']->subtract($line['discount'])), $lines),
                (string) $bill->subtotal()->subtract($bill->discount()),
                (string) $bill->shipping(),
                $chosen['view']->id ?? null,
                $payment,
                $needsShipping ? $shipping?->country : $billing?->country,
                $customerId,
                $settings->currency,
            ), $shipping, $billing, $reverseCharge);
        }

        return ['lines' => $lines, 'bill' => $bill, 'needs_shipping' => $needsShipping, 'options' => $options, 'chosen' => $chosen['view'] ?? null, 'discounts' => $discounts, 'free_from' => $freeFrom, 'ships_from' => $shipsFrom];
    }

    /**
     * Adds the order's fees to the bill, taxed as items of their tax group are.
     */
    private function charge(Bill $bill, FeeQuery $query, ?TaxAddress $shipping, ?TaxAddress $billing, bool $reverseCharge): void
    {
        foreach ($this->fees->of($query) as $fee) {
            $taxes = $fee['taxable'] && !$reverseCharge ? $this->taxes($fee['tax_group_id'], $shipping, $billing) : [];
            $amount = $fee['amount'];
            if ($bill->includesTax() && $fee['taxable'] && $taxes === []) {
                $amount = Tax::without($amount, $this->shopTaxes($fee['tax_group_id']));
            }
            $bill->addFee($fee['label'], $amount, $taxes);
        }
    }

    /**
     * The fees the order pays, as it records them.
     *
     * @param Priced $priced
     *
     * @return list<NewOrderFee>
     */
    public function orderFees(array $priced): array
    {
        return array_map(static fn(array $fee): NewOrderFee => new NewOrderFee($fee['label'], (string) $fee['amount']), $priced['bill']->fees());
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

        return array_map(function (array $line): NewOrderLine {
            $quote = $line['quote'];
            $taken = $this->take($quote, $line['quantity']);

            return $this->line($line, $taken);
        }, $priced['lines']);
    }

    /**
     * @param PricedLine       $line
     * @param list<TakenStock> $taken
     */
    private function line(array $line, array $taken): NewOrderLine
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
            (string) $line['tax'],
            $line['quote']->type === 'physical',
            $taken,
            $line['quote']->downloads,
            $line['quote']->downloadLimit,
            $line['quote']->downloadExpiryDays,
            $backordered,
            $backordered > 0 && $line['quote']->backorders === 'notify',
            (string) $line['discount'],
            array_map(static fn(QuotedBundleItem $item): array => ['name' => $item->name, 'quantity' => $item->quantity], $line['quote']->contents),
            $line['quote']->contents === [] ? [] : $line['bundle_choices'],
        );
    }

    /**
     * The coupons the order uses, as it records them.
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
        $bill = $priced['bill'];

        // Collected from the shop: the place, with when it is open, is where the order goes.
        $pickupPoint ??= $chosen?->collectAt;
        $label = $pickupPoint === null ? null : ($chosen?->collectAt !== null && $pickupPoint->hours !== '' ? "{$pickupPoint->label()} ({$pickupPoint->hours})" : $pickupPoint->label());

        return $chosen === null || !$priced['needs_shipping'] ? null : new NewShipping($chosen->id, $chosen->carrier, $chosen->name, (string) $bill->shipping(), (string) $bill->shippingTax(), $pickupPoint?->id, $label);
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
     * @param Priced $priced
     *
     * @return list<TaxLineView>
     */
    public function taxLines(array $priced): array
    {
        return array_map(static fn(BilledTax $tax): TaxLineView => new TaxLineView($tax->name, $tax->percent, (string) $tax->total()), $priced['bill']->taxes());
    }

    /**
     * @return list<Tax>
     */
    private function taxes(?int $groupId, ?TaxAddress $shipping, ?TaxAddress $billing): array
    {
        return array_map(
            self::tax(...),
            $this->tax->taxes(new TaxQuery($groupId, $shipping, $billing))->taxes,
        );
    }

    private static function tax(AppliedTaxView $tax): Tax
    {
        return new Tax($tax->rateId === null ? "{$tax->name}|{$tax->percent}" : (string) $tax->rateId, $tax->name, $tax->percent, $tax->compound);
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
     * The rates a price including tax holds at the shop's own address.
     *
     * @return list<Tax>
     */
    private function shopTaxes(?int $groupId): array
    {
        return array_map(self::tax(...), $this->tax->shopTaxes($groupId)->taxes);
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
