<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\OrderAdjustment;
use OrillaCart\Ordering\Domain\OrderLine;
use OrillaCart\Ordering\Domain\OrderStatus;
use OrillaCart\Ordering\Domain\OrderTax;

final readonly class OrderLineView
{
    /**
     * @param list<array{attribute: string, option: string}> $options
     * @param list<int>                                    $optionIds
     * @param list<LineTaxView>    $taxes       each rate charged on it
     * @param list<AdjustmentView> $adjustments the changes to its price after its unit price
     */
    public function __construct(
        public int $productId,
        public ?int $variationId,
        public string $name,
        public ?string $sku,
        public array $options,
        public int $quantity,
        public string $unitPrice,
        public string $total,
        public string $tax,
        public array $optionIds = [],
        /** How many the shop owes; customer views show it only when backorderShown. */
        public int $backordered = 0,
        public bool $backorderShown = false,
        public int $refundedQuantity = 0,
        /** What the changes to its price took off, in all; the tax is on what is left. */
        public string $discount = '0',
        /** @var list<array{name: string, quantity: int}> what one bundle holds */
        public array $contents = [],
        /** @var list<int> for a bundle, the variations chosen and the optional products added */
        public array $bundleChoices = [],
        /** The money given back for the line, with its tax. */
        public string $refundedAmount = '0',
        /** Items of a refunded order that can still go back in stock. */
        public int $restockable = 0,
        /** Something to send: not a download or a service. */
        public bool $ships = true,
        /** @var list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}> the answers to the product's forms, as sold */
        public array $personalisation = [],
        /** "item", "shipping" or "fee", as tax services and reports tell lines apart: an item given is an item. */
        public string $kind = 'item',
        public array $taxes = [],
        public array $adjustments = [],
        /** The line's place in the order, as refunds and edits name it. */
        public int $position = 0,
        /** @var array{code: string, description: string}|null for an item given: the discount that gave it */
        public ?array $givenBy = null,
        /** What the line is: "item", "given" (by a discount), "shipping" or "fee". */
        public string $type = 'item',
    ) {}

    /**
     * @param bool $forShop in the shop's own words, where the customer read another language (design 51)
     */
    public static function fromLine(OrderLine $line, bool $forShop = false): self
    {
        return new self($line->productId(), $line->variationId(), $forShop ? $line->shopName() : $line->name(), $line->sku(), $forShop ? $line->shopOptions() : $line->options(), $line->quantity(), (string) $line->unitPrice(), (string) $line->total(), (string) $line->tax(), $line->optionIds(), $line->backordered(), $line->isBackorderShown(), $line->refundedQuantity(), (string) $line->discount(), $forShop ? $line->shopContents() : $line->contents(), $line->bundleChoices(), (string) $line->refundedAmount(), $line->order()->status() === OrderStatus::Refunded ? $line->restockable() : 0, $line->ships(), $forShop ? $line->shopPersonalisation() : $line->personalisation(), $line->type()->kind(), array_map(self::tax(...), $line->taxes()), array_map(self::adjustment(...), $line->adjustments()), $line->position(), ($given = $line->givenBy()) === null ? null : ['code' => $given->code, 'description' => $given->description], $line->type()->value);
    }

    private static function tax(OrderTax $tax): LineTaxView
    {
        $place = $tax->jurisdiction();

        return new LineTaxView($tax->name(), $tax->percent(), $place->country, $place->region, $place->level, $place->code, $place->name, (string) $tax->taxable(), (string) $tax->amount(), $tax->isCompound(), (string) $tax->refunded(), $tax->isExempt());
    }

    private static function adjustment(OrderAdjustment $row): AdjustmentView
    {
        $adjustment = $row->adjustment();

        return new AdjustmentView($adjustment->kind, $adjustment->source, $adjustment->label, (string) $adjustment->amount, $adjustment->scope, $adjustment->freeShipping, $adjustment->reason, $row->id(), $adjustment->change);
    }
}
