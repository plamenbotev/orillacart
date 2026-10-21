<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application\Dto;

final readonly class GiftCardPage
{
    /**
     * @param list<GiftCardView> $cards
     */
    public function __construct(
        public array $cards,
        public int $total,
        public int $page,
        public int $pages,
    ) {}
}
