<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Catalog\Application\Dto\ItemQuote;
use OrillaCart\Catalog\Application\Dto\QuotedOption;
use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Application\ProductQuotes;
use OrillaCart\Checkout\Application\Dto\ShippingChoiceView;
use OrillaCart\Checkout\Domain\Bill;
use OrillaCart\Checkout\Domain\BilledTax;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Checkout\Domain\Tax;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\Dto\TakenStock;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Inventory\Domain\InsufficientStock;
use OrillaCart\Ordering\Application\Dto\NewOrderLine;
use OrillaCart\Ordering\Application\Dto\NewShipping;
use OrillaCart\Ordering\Application\Dto\TaxLineView;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shipping\Application\Dto\ParcelItem;
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
 * @phpstan-type PricedLine array{quote: ItemQuote, option_ids: list<int>, quantity: int, unit_price: string, total: Money, tax: Money}
 * @phpstan-type Priced array{lines: list<PricedLine>, bill: Bill, needs_shipping: bool, options: list<ShippingChoiceView>, chosen: ?ShippingChoiceView}
 */
final readonly class OrderPricing
{
    public function __construct(
        private ProductQuotes $quotes,
        private TaxCalculator $tax,
        private ShippingQuotes $shipping,
        private StockService $stock,
        private SettingsService $settings,
    ) {}

    /**
     * Prices items with the customer's addresses: taxes, the shipping rates offered and the chosen one (or the
     * first), and the bill. An item may carry its own unit price, which the shop sets when it edits an order.
     *
     * @param list<array{product_id: int, option_ids: list<int>, quantity: int, unit_price?: ?string}> $items
     *
     * @return Priced
     *
     * @throws CheckoutRuleViolation for an item no longer sold, or not in that quantity
     */
    public function price(array $items, ?TaxAddress $shipping, ?TaxAddress $billing, ?int $rateId): array
    {
        $decimals = $this->settings->current()->priceDecimals;
        $bill = new Bill($decimals);
        $lines = [];
        $parcel = [];
        foreach ($items as $line) {
            try {
                $quote = $this->quotes->quote(new QuoteRequest($line['product_id'], $line['option_ids']));
            } catch (NotFound|RuleViolation) {
                throw new CheckoutRuleViolation('An item is no longer sold. Review the items and try again.', 'orillacart_cart_changed');
            }
            if (!$quote->forSale || ($quote->available !== null && $quote->available < $line['quantity'])) {
                throw new CheckoutRuleViolation(\sprintf('%s is not available in that quantity. Review the items and try again.', $quote->name), 'orillacart_cart_changed');
            }
            $unitPrice = $line['unit_price'] ?? $quote->unitPrice;
            $total = Money::of($unitPrice)->times($line['quantity']);
            $taxes = $quote->taxable ? $this->taxes($quote->taxGroupId, $shipping, $billing) : [];
            $lines[] = ['quote' => $quote, 'option_ids' => $line['option_ids'], 'quantity' => $line['quantity'], 'unit_price' => $unitPrice, 'total' => $total, 'tax' => $bill->addItem($total, $taxes)];
            if ($quote->type === 'physical') {
                $parcel[] = new ParcelItem($line['quantity'], $quote->weight, $quote->length, $quote->width, $quote->height, $quote->shippingClassId);
            }
        }

        $needsShipping = $parcel !== [];
        $options = [];
        $chosen = null;
        if ($needsShipping) {
            $offered = $this->shipping->options(new ShippingQuery($shipping?->country, $shipping?->state, $parcel, (string) $bill->subtotal()));
            foreach ($offered as $option) {
                $taxes = $option->taxable ? $this->taxes($option->taxGroupId, $shipping, $billing) : [];
                $price = Money::of($option->price);
                $tax = array_reduce($taxes, static fn(Money $sum, Tax $rate): Money => $sum->add($price->percent($rate->percent)->rounded($decimals)), Money::zero());
                $view = new ShippingChoiceView($option->rateId, $option->carrier, $option->name, (string) $price->rounded($decimals), (string) $tax, (string) $price->rounded($decimals)->add($tax));
                $options[] = $view;
                // The customer's choice when it is offered, otherwise the first rate, so the totals include shipping.
                if ($chosen === null || $option->rateId === $rateId) {
                    $chosen = $chosen !== null && $chosen['view']->rateId === $rateId ? $chosen : ['view' => $view, 'price' => $price, 'taxes' => $taxes];
                }
            }
            if ($chosen !== null) {
                $bill->ship($chosen['price'], $chosen['taxes']);
            }
        }

        return ['lines' => $lines, 'bill' => $bill, 'needs_shipping' => $needsShipping, 'options' => $options, 'chosen' => $chosen['view'] ?? null];
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
        return array_map(fn(array $line): NewOrderLine => new NewOrderLine(
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
            $this->take($line['quote'], $line['quantity']),
            $line['quote']->downloads,
            $line['quote']->downloadLimit,
            $line['quote']->downloadExpiryDays,
        ), $priced['lines']);
    }

    /**
     * @param Priced $priced
     */
    public function shipping(array $priced): ?NewShipping
    {
        $chosen = $priced['chosen'];
        $bill = $priced['bill'];

        return $chosen === null || !$priced['needs_shipping'] ? null : new NewShipping($chosen->rateId, $chosen->carrier, $chosen->name, (string) $bill->shipping(), (string) $bill->shippingTax());
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
        $refs = $quote->variationId !== null
            ? [StockItemRef::variation($quote->variationId)]
            : [StockItemRef::product($quote->productId), ...array_map(static fn(QuotedOption $option): StockItemRef => StockItemRef::option($option->id), $quote->options)];
        // Stock that does not limit the item takes nothing; items always available or never sold were checked above.
        try {
            return $quote->available === null ? [] : $this->stock->take($refs, $quantity);
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
