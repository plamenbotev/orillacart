<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Application;

use OrillaCart\Ordering\Application\ReceiptLinks;
use OrillaCart\Shared\Security\Signatures;
use Psr\Clock\ClockInterface;

/**
 * The keys of invoice links: the document's number and the day the link was made, signed for invoices alone. An
 * invoice link opens that one document and nothing of its order: a printed or forwarded invoice must not carry the
 * key to the receipt, where the order can be paid, returned or ordered again. A key works as long as a receipt
 * link does (ReceiptLinks::VALID_DAYS).
 */
final readonly class InvoiceKeys
{
    private const string PURPOSE = 'invoice';

    public function __construct(
        private Signatures $signatures,
        private ClockInterface $clock,
    ) {}

    public function issue(string $number): string
    {
        $day = intdiv($this->clock->now()->getTimestamp(), 86400);

        return "{$day}-" . $this->signatures->sign(self::PURPOSE, "{$number}|{$day}");
    }

    /** Whether the key was made for the document with this number and has not expired. */
    public function opens(string $number, #[\SensitiveParameter] string $key): bool
    {
        if (preg_match('/^(\d{1,6})-([a-f0-9]{32})$/', $key, $parts) !== 1 || !$this->signatures->verify(self::PURPOSE, "{$number}|{$parts[1]}", $parts[2])) {
            return false;
        }

        return intdiv($this->clock->now()->getTimestamp(), 86400) - (int) $parts[1] < ReceiptLinks::VALID_DAYS;
    }
}
