<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

/**
 * A customer's own certificate, as their account shows it: without the shop's note.
 */
final readonly class AccountTaxExemptionView
{
    /**
     * @param list<string> $regions empty for everywhere
     */
    public function __construct(
        public int $id,
        public string $reason,
        public string $certificate,
        public array $regions,
        public ?string $expiresOn,
        /** "pending", "approved" or "refused". */
        public string $status,
        public ?string $refusal,
        public string $sentAt,
    ) {}

    public static function fromView(TaxExemptionView $view): self
    {
        return new self($view->id, $view->reason, $view->certificate, $view->regions, $view->expiresOn, $view->status, $view->refusal, $view->createdAt);
    }
}
