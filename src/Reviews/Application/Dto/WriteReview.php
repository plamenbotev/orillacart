<?php

declare(strict_types=1);

namespace OrillaCart\Reviews\Application\Dto;

use OrillaCart\Ordering\Application\ReceiptLinks;
use OrillaCart\Reviews\Domain\NewReview;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A review sent from the product page. A visitor who is not logged in gives a name and email, or comes from
 * their receipt, whose link says who they are and what they bought.
 */
final readonly class WriteReview
{
    public function __construct(
        #[Assert\Range(min: 1, max: 5)]
        public int $rating = 0,
        #[Assert\NotBlank]
        #[Assert\Length(max: NewReview::MAX_TEXT_LENGTH)]
        public string $text = '',
        #[Assert\Length(max: NewReview::MAX_NAME_LENGTH)]
        public ?string $name = null,
        #[Assert\Email]
        #[Assert\Length(max: 100)]
        public ?string $email = null,
        #[Assert\Regex(pattern: '/^' . ReceiptLinks::PATTERN . '$/')]
        public ?string $receipt = null,
    ) {}
}
