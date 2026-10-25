<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Application\Dto;

use OrillaCart\Storefront\Domain\OrderButton;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class UpdateLegalSettings
{
    public function __construct(
        #[Assert\Positive]
        public ?int $terms = null,
        #[Assert\Positive]
        public ?int $withdrawal = null,
        #[Assert\Positive]
        public ?int $delivery = null,
        #[Assert\Positive]
        public ?int $legalNotice = null,
        public bool $acceptTerms = false,
        #[Assert\Choice(callback: [OrderButton::class, 'values'])]
        public string $orderButton = 'place_order',
    ) {}
}
