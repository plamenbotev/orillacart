<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * "Confirm withdrawal", from the link emailed to the order's address: who withdraws, where the confirmation goes,
 * and the items, all of them when none are chosen.
 */
final readonly class ConfirmWithdrawal
{
    /**
     * @param list<ReturnItem> $items
     */
    public function __construct(
        #[\SensitiveParameter]
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public string $token,
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
