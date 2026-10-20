<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: email me when this product, or this variation of it, is back in stock.
 */
final readonly class AskBackInStock
{
    public function __construct(
        #[Assert\Positive]
        public int $productId,
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 254)]
        public string $email,
        #[Assert\Positive]
        public ?int $variationId = null,
    ) {}
}
