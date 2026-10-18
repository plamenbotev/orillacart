<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\OrderNote;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: the shop says the money of an order came.
 */
final readonly class MarkOrderPaid
{
    public function __construct(
        /** For the order's history, such as how the money came. */
        #[Assert\Length(max: OrderNote::MAX_LENGTH)]
        public string $note = '',
    ) {}
}
