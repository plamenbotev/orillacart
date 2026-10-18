<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Application\Dto;

use OrillaCart\Payment\Domain\Refund;

final readonly class RefundView
{
    /**
     * @param 'provider'|'manual'|'external' $method
     * @param 'pending'|'accepted'|'done'|'failed'      $status
     * @param list<array{line: int, quantity: int}> $lines
     */
    public function __construct(
        public int $id,
        public string $method,
        public string $status,
        public string $amount,
        public string $currency,
        public array $lines,
        public bool $shipping,
        public bool $restock,
        public string $reason,
        public ?string $providerReference,
        public ?string $error,
        public string $createdAt,
        public ?string $settledAt,
    ) {}

    public static function fromRefund(Refund $refund): self
    {
        $lines = [];
        foreach ($refund->quantities() as $line => $quantity) {
            $lines[] = ['line' => $line, 'quantity' => $quantity];
        }

        return new self(
            $refund->id(),
            $refund->method()->value,
            $refund->status()->value,
            (string) $refund->amount(),
            $refund->currency(),
            $lines,
            $refund->coversShipping(),
            $refund->restocks(),
            $refund->reason(),
            $refund->providerReference(),
            $refund->error(),
            $refund->createdAt()->format(\DATE_ATOM),
            $refund->settledAt()?->format(\DATE_ATOM),
        );
    }
}
