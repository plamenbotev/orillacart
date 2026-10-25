<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Dto;

use OrillaCart\Storefront\Domain\OrderButton;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The legal pages and checkout's rules. Left out, they follow the law of the shop's country.
 */
final readonly class LegalAnswers
{
    public function __construct(
        /** Draft the pages the shop has none for. */
        public bool $pages = true,
        public ?bool $acceptTerms = null,
        #[Assert\Choice(callback: [OrderButton::class, 'values'])]
        public ?string $orderButton = null,
    ) {}
}
