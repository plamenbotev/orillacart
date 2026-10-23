<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

/**
 * What an import scheme says about an order's goods: none applies (taxed as the shop's settings say), or one does
 * and the shop charges the destination's tax under its number, or it charges none and the customer pays at the
 * border.
 */
final readonly class ImportDecision
{
    public function __construct(
        public ?string $scheme = null,
        /** The shop's number under the scheme, when it charges. */
        public ?string $number = null,
        public bool $charges = false,
    ) {}

    public function applies(): bool
    {
        return $this->scheme !== null;
    }
}
