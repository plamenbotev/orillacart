<?php

declare(strict_types=1);

namespace OrillaCart\GiftCards\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Query: a page of gift cards, newest first; the search matches whom a card is for, or the end of its code.
 */
final readonly class GiftCardQuery
{
    public function __construct(
        #[Assert\Length(max: 100)]
        public string $search = '',
        #[Assert\Positive]
        public int $page = 1,
        #[Assert\Range(min: 1, max: 100)]
        public int $perPage = 20,
    ) {}
}
