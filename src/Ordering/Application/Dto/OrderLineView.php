<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\OrderLine;
use OrillaCart\Ordering\Domain\OrderStatus;

final readonly class OrderLineView
{
    /**
     * @param list<array{attribute: string, option: string}> $options
     * @param list<int>                                    $optionIds
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
        /** What coupons took off the total; the tax is on what is left. */
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
    ) {}

    public static function fromLine(OrderLine $line): self
    {
        return new self($line->productId(), $line->variationId(), $line->name(), $line->sku(), $line->options(), $line->quantity(), (string) $line->unitPrice(), (string) $line->total(), (string) $line->tax(), $line->optionIds(), $line->backordered(), $line->isBackorderShown(), $line->refundedQuantity(), (string) $line->discount(), $line->contents(), $line->bundleChoices(), (string) $line->refundedAmount(), $line->order()->status() === OrderStatus::Refunded ? $line->restockable() : 0, $line->ships(), $line->personalisation());
    }
}
