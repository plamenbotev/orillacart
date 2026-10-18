<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Catalog\Domain\AdjustmentKind;
use OrillaCart\Catalog\Domain\OptionDraft;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An option of an attribute being saved; an existing option keeps its id.
 */
final readonly class SaveOption
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: OptionDraft::MAX_NAME_LENGTH)]
        public string $name,
        #[Assert\Regex(SaveProduct::SIGNED_AMOUNT, message: SaveProduct::SIGNED_AMOUNT_MESSAGE)]
        public string $adjustment = '0',
        #[Assert\Positive]
        public ?int $id = null,
        /** amount, or percent: the adjustment is a percentage of the product price */
        #[Assert\Choice(callback: [AdjustmentKind::class, 'values'])]
        public string $adjustmentKind = 'amount',
    ) {}
}
