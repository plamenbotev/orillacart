<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A sale price with an optional start and end, as ISO 8601 date-times.
 */
final readonly class SaveSale
{
    public function __construct(
        #[Assert\Regex(SaveProduct::AMOUNT, message: SaveProduct::AMOUNT_MESSAGE)]
        public string $price,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $until = null,
    ) {}
}
