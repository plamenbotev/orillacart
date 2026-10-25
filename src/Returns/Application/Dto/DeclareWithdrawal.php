<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the consumer gives to withdraw: their name, which order (its number and email), where the confirmation
 * goes, and the items, all of them when none are chosen.
 */
final readonly class DeclareWithdrawal
{
    /**
     * @param list<ReturnItem> $items
     */
    public function __construct(
        #[Assert\Positive]
        public int $number,
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email,
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public string $name,
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $confirmationEmail,
        #[Assert\Count(max: 200)]
        #[Assert\Valid]
        public array $items = [],
    ) {}
}
