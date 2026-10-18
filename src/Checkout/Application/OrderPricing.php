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
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\Dto\TakenStock;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Inventory\Domain\InsufficientStock;
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
 * Prices items as an order: catalog prices, taxes at the customer's address, shipping and the bill; and turns
 * a priced order into order lines, taking their stock. Checkout and order editing price the same way.
 *
 * Coupons take money off the items before tax is charged, and free shipping makes every way to ship cost nothing.
 *
 * @phpstan-type PricedLine array{quote: ItemQuote, option_ids: list<int>, quantity: int, unit_price: string, total: Money, discount: Money, tax: Money}
 * @phpstan-type Priced array{lines: list<PricedLine>, bill: Bill, needs_shipping: bool, options: list<ShippingChoiceView>, chosen: ?ShippingChoiceView, discounts: AppliedDiscounts}
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
    ) {}

    /**
     * Prices items with the customer's addresses: taxes, the shipping rates offered and the chosen one (or the
     * first), and the bill. An item may carry its own unit price, which the shop sets when it edits an order.
     *
     * @param list<array{product_id: int, option_ids: list<int>, quantity: int, unit_price?: ?string}> $items
     * @param string|null  $postcode   the shipping address's postcode, for rates that ship to some postcodes only
     * @param list<string> $codes      coupon codes; those that do not hold are left out, with the reason
     * @param string|null  $email      the customer's, for limits per customer, once known
     *
     * @return Priced
     *
     * @throws CheckoutRuleViolation for an item no longer sold, or not in that quantity
     */
    public function price(array $items, ?TaxAddress $shipping, ?TaxAddress $billing, ?string $shippingOption, ?string $postcode = null, array $codes = [], ?int $customerId = null, ?string $email = null): array
    {
        $settings = $this->settings->current();
        $decimals = $settings->priceDecimals;
        $bill = new Bill($decimals);
        $lines = [];
        $parcel = [];
        $taxesOf = [];
        foreach ($items as $line) {
            try {
                $quote = $this->quotes->quote(new QuoteRequest($line['product_id'], $line['option_ids'], $line['quantity'], $customerId));
            } catch (NotFound|RuleViolation) {
                throw new CheckoutRuleViolation('An item is no longer sold. Review the items and try again.', 'orillacart_cart_changed');
            }
            if (!$quote->forSale || ($quote->available !== null && $quote->available < $line['quantity'])) {
                throw new CheckoutRuleViolation(\sprintf('%s is not available in that quantity. Review the items and try again.', $quote->name), 'orillacart_cart_changed');
            }
            $unitPrice = $line['unit_price'] ?? $quote->unitPrice;
            $total = Money::of($unitPrice)->times($line['quantity']);
            $taxesOf[] = $quote->taxable ? $this->taxes($quote->taxGroupId, $shipping, $billing) : [];
            $lines[] = ['quote' => $quote, 'option_ids' => $line['option_ids'], 'quantity' => $line['quantity'], 'unit_price' => $unitPrice, 'total' => $total, 'discount' => Money::zero(), 'tax' => Money::zero()];
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
        ));
        foreach ($lines as $index => $line) {
            $lines[$index]['discount'] = $discounts->forLine($index);
            $lines[$index]['tax'] = $bill->addItem($line['total'], $taxesOf[$index], $lines[$index]['discount']);
        }
        $free = $discounts->freeShipping();

        $needsShipping = $parcel !== [];
        $options = [];
        $chosen = null;
        if ($needsShipping) {
            $offered = $this->shipping->options(new ShippingQuery($shipping?->country, $shipping?->state, $parcel, (string) $bill->subtotal()->subtract($bill->discount()), $postcode));
            foreach ($offered as $option) {
                $taxes = $option->taxable && !$free ? $this->taxes($option->taxGroupId, $shipping, $billing) : [];
                $price = $free ? Money::zero() : Money::of($option->price);
                $tax = array_reduce($taxes, static fn(Money $sum, Tax $rate): Money => $sum->add($price->percent($rate->percent)->rounded($decimals)), Money::zero());
                $view = new ShippingChoiceView($option->id, $option->carrier, $option->name, (string) $price->rounded($decimals), (string) $tax, (string) $price->rounded($decimals)->add($tax), $option->toPickupPoint);
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

        return ['lines' => $lines, 'bill' => $bill, 'needs_shipping' => $needsShipping, 'options' => $options, 'chosen' => $chosen['view'] ?? null, 'discounts' => $discounts];
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

        return $chosen === null || !$priced['needs_shipping'] ? null : new NewShipping($chosen->id, $chosen->carrier, $chosen->name, (string) $bill->shipping(), (string) $bill->shippingTax(), $pickupPoint?->id, $pickupPoint?->label());
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
            static fn(AppliedTaxView $tax): Tax => new Tax($tax->rateId === null ? "{$tax->name}|{$tax->percent}" : (string) $tax->rateId, $tax->name, $tax->percent),
            $this->tax->taxes(new TaxQuery($groupId, $shipping, $billing))->taxes,
        );
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
                    array_push($taken, ...$this->stock->take($refs, $item->quantity * $quantity, $item->backorders !== 'no'));
                } catch (InsufficientStock) {
                    throw new CheckoutRuleViolation(\sprintf('%s sold out in the meantime. Review the items and try again.', $quote->name), 'orillacart_out_of_stock');
                }
            }

            return $taken;
        }
        $refs = $quote->variationId !== null
            ? [StockItemRef::variation($quote->variationId)]
            : [StockItemRef::product($quote->productId), ...array_map(static fn(QuotedOption $option): StockItemRef => StockItemRef::option($option->id), $quote->options)];
        // Stock that does not limit the item takes nothing; items always available or never sold were checked above.
        // Backorders lift the limit, but the stock is still taken and the shortfall owed.
        try {
            return $quote->available === null && $quote->inStock === null ? [] : $this->stock->take($refs, $quantity, $quote->backorders !== 'no');
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
