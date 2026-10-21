<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application\Dto;

/**
 * A gift card or store credit with every change of its balance.
 */
final readonly class GiftCardDetailView
{
    /**
     * @param list<GiftCardEntryView> $entries newest first
     */
    public function __construct(
        public GiftCardView $card,
        public array $entries,
    ) {}
}
