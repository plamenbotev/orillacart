<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Application\Dto;

use OrillaCart\Ordering\Application\Dto\OrderView;

/**
 * What a printable invoice or credit note shows: the document, the seller as it was when issued, and the order.
 */
final readonly class InvoiceDocument
{
    /**
     * @param array<string, string>                 $seller
     * @param list<array{line: int, quantity: int}>                       $lines  the order lines a credit note gives back
     * @param list<array{name: string, percent: string, amount: string}> $taxes  the tax a credit note gives back, by rate
     */
    public function __construct(
        public InvoiceView $invoice,
        public array $seller,
        public OrderView $order,
        public array $lines,
        /** For a credit note: the invoice it corrects. */
        public ?string $corrects,
        public array $taxes = [],
        /**
         * @var array{currency: string, rate: string, taxes: list<array{name: string, percent: string, amount: string}>, total: string}|null
         *      for a document in another currency than the shop's: its taxes in the shop's, at the order's exchange
         *      rate, as VAT law asks of invoices in another currency
         */
        public ?array $inShopCurrency = null,
    ) {}
}
