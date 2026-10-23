<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

use OrillaCart\Tax\Domain\TaxExemption;

/**
 * A customer's exemption from tax, as the shop keeps it and orders record it.
 */
final readonly class TaxExemptionView
{
    /**
     * @param list<string> $regions empty for everywhere
     */
    public function __construct(
        public int $id,
        public int $customerId,
        public string $reason,
        public string $certificate,
        public array $regions,
        public ?string $expiresOn,
        public string $note,
        public string $createdAt,
    ) {}

    public static function fromExemption(TaxExemption $exemption): self
    {
        return new self(
            $exemption->id(),
            $exemption->customerId(),
            $exemption->reason()->value,
            $exemption->certificate(),
            $exemption->regions(),
            $exemption->expiresOn()?->format('Y-m-d'),
            $exemption->note(),
            $exemption->createdAt()->format(\DATE_ATOM),
        );
    }
}
