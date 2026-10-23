<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Domain;

interface InvoiceRepository
{
    public function findByNumber(string $number): ?Invoice;

    /** @return list<Invoice> oldest first */
    public function ofOrder(int $orderId): array;

    /**
     * The series' counter, locked until the transaction ends (SELECT … FOR UPDATE); made when it is new. Only
     * inside a transaction.
     */
    /**
     * Orders with an invoice or credit note whose number contains the text.
     *
     * @return list<int> order ids, up to the limit
     */
    public function orderIdsNumbered(string $text, int $limit): array;

    public function lockedCounter(string $series): InvoiceCounter;

    public function save(Invoice $invoice): void;

    public function saveCounter(InvoiceCounter $counter): void;
}
