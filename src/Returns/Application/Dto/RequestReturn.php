<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application\Dto;

use OrillaCart\Returns\Domain\ItemReturn;
use OrillaCart\Returns\Domain\ReturnReason;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: the customer asks to send some of the order's items back.
 */
final readonly class RequestReturn
{
    /**
     * @param list<ReturnItem> $items
     */
    public function __construct(
        #[Assert\Valid]
        #[Assert\Count(min: 1, max: 500)]
        public array $items,
        #[Assert\Choice(callback: [ReturnReason::class, 'values'])]
        public string $reason,
        #[Assert\Length(max: ItemReturn::MAX_COMMENT_LENGTH)]
        public string $comment = '',
    ) {}
}
