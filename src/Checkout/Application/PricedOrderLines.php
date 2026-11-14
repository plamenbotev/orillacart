<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Catalog\Application\Dto\ItemQuote;
use OrillaCart\Catalog\Application\Dto\QuotedBundleItem;
use OrillaCart\Catalog\Application\Dto\QuotedOption;
use OrillaCart\Checkout\Domain\BilledCharge;
use OrillaCart\Checkout\Domain\ChargedTax;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Coupons\Application\Dto\AppliedCoupon;
use OrillaCart\Coupons\Application\Dto\AppliedDiscounts;
use OrillaCart\Forms\Application\ShopForms;
use OrillaCart\Inventory\Application\Dto\StockItemRef;
use OrillaCart\Inventory\Application\Dto\TakenStock;
use OrillaCart\Inventory\Application\StockService;
use OrillaCart\Inventory\Domain\InsufficientStock;
use OrillaCart\Ordering\Application\Dto\AdjustmentView;
use OrillaCart\Ordering\Application\Dto\LineTaxView;
use OrillaCart\Ordering\Application\Dto\NewOrderLine;
use OrillaCart\Ordering\Domain\PriceAdjustment;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\TaxJurisdiction;

/**
 * A priced order (OrderPricing) as the order's lines: the items, taking their stock, with their taxes and what
 * changed their prices, then the shipping and fees. Checkout, manual orders and order editing make them the same
 * way.
 *
 * @phpstan-import-type Priced from OrderPricing
 * @phpstan-import-type PricedLine from OrderPricing
 */
final readonly class PricedOrderLines
{
    public function __construct(
        private StockService $stock,
        private ShopForms $forms,
    ) {}

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
            $given = $line['given'] === null ? null : array_find($priced['discounts']->coupons, static fn(AppliedCoupon $coupon): bool => $coupon->code === $line['given']);
            $lines[] = $this->line($line, $taken, self::lineTaxes($charged), [...self::couponAdjustments($priced['discounts'], $priced['coupon_lines'], $index), ...$priced['changes'][$index] ?? []], $given);
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
        $shipping = $priced['chosen'] === null || !$priced['needs_shipping'] ? [] : $bill->charges(BilledCharge::SHIPPING);
        $shop = $priced['shop_shipping'];
        foreach ($shipping as $charge) {
            $free = array_values(array_filter($priced['discounts']->coupons, static fn(AppliedCoupon $coupon): bool => $coupon->freeShipping));
            $lines[] = self::chargeLine($charge, 'shipping', array_map(
                static fn(AppliedCoupon $coupon): AdjustmentView => new AdjustmentView($coupon->automatic ? PriceAdjustment::AUTOMATIC : PriceAdjustment::COUPON, $coupon->code, $coupon->description, '0', PriceAdjustment::ORDER, true),
                $free,
            ), $shop === null ? null : "{$shop['carrier']} – {$shop['rate']}");
        }
        foreach ($bill->charges(BilledCharge::FEE) as $index => $charge) {
            $fee = $priced['fee_lines'][$index] ?? null;
            if ($fee !== null && $fee['type'] === 'charge') {
                $lines[] = new NewOrderLine(null, null, [], [], $charge->label, null, $fee['quantity'], (string) $fee['unit'], (string) $charge->amount, self::lineTaxes($charge->taxes), false, [], type: 'charge', chargeSource: $fee['source'], chargeTaxable: $fee['taxable'], chargeTaxGroupId: $fee['tax_group_id'], chargeFor: $fee['for_product_ids'] ?? []);
                continue;
            }
            $lines[] = self::chargeLine($charge, 'fee', [], $priced['shop_fees'][$index] ?? null);
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
    private static function chargeLine(BilledCharge $charge, string $type, array $adjustments, ?string $shopLabel = null): NewOrderLine
    {
        return new NewOrderLine(null, null, [], [], $charge->label, null, 1, (string) $charge->amount, (string) $charge->amount, self::lineTaxes($charge->taxes), false, [], adjustments: $adjustments, type: $type, shopWords: $shopLabel === null || $shopLabel === $charge->label ? [] : ['name' => $shopLabel]);
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
    private function line(array $line, array $taken, array $taxes, array $adjustments, ?AppliedCoupon $given = null): NewOrderLine
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
            OrderPricing::options($line['quote']),
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
            type: match (true) {
                $line['given'] !== null => 'given',
                $line['add_on'] !== null => 'add_on',
                default => 'item',
            },
            shopWords: $this->shopWords($line),
            givenCode: $line['given'],
            givenDescription: $given->description ?? '',
            addOnType: $line['add_on']['type'] ?? null,
            addOnLabel: $line['add_on']['label'] ?? '',
        );
    }

    /**
     * The line's words in the shop's own language, where the shopper read others (design 51): what the order keeps
     * for the shop beside the shopper's.
     *
     * @param PricedLine $line
     *
     * @return array{name?: string, options?: list<array{attribute: string, option: string}>, contents?: list<array{name: string, quantity: int}>, personalisation?: list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}>}
     */
    private function shopWords(array $line): array
    {
        $quote = $line['quote'];
        $words = [];
        if ($quote->shopName !== null) {
            $words['name'] = $quote->shopName;
        }
        $options = array_map(static fn(QuotedOption $option): array => ['attribute' => $option->shopAttribute ?? $option->attribute, 'option' => $option->shopName ?? $option->name], $quote->options);
        if ($options !== OrderPricing::options($quote)) {
            $words['options'] = $options;
        }
        $contents = array_map(static fn(QuotedBundleItem $item): array => ['name' => $item->shopName ?? $item->name, 'quantity' => $item->quantity], $quote->contents);
        if ($contents !== array_map(static fn(QuotedBundleItem $item): array => ['name' => $item->name, 'quantity' => $item->quantity], $quote->contents)) {
            $words['contents'] = $contents;
        }
        $answers = $this->forms->shopWords($line['personalisation']);
        if ($answers !== null) {
            $words['personalisation'] = array_map(static fn(array $answer, array $own): array => ['label' => $own['label'], 'answer' => $own['answer']] + $answer, $line['personalisation'], $answers);
        }

        return $words;
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
                    throw new CheckoutRuleViolation('%s sold out in the meantime. Review the items and try again.', 'orillacart_out_of_stock', values: [$quote->name]);
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
            throw new CheckoutRuleViolation('%s sold out in the meantime. Review the items and try again.', 'orillacart_out_of_stock', values: [$quote->name]);
        }
    }
}
