<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use OrillaCart\Shared\Domain\Money;

/**
 * An order line as checkout priced it.
 */
final readonly class LineDraft
{
    /**
     * @param list<int>                                    $optionIds
     * @param list<array{attribute: string, option: string}> $options   the chosen options as the customer saw them
     * @param list<TakenItem>                              $taken
     * @param list<LineDownload>                           $downloads what the buyer may download once paid
     */
    public function __construct(
        public int $productId,
        public ?int $variationId,
        public array $optionIds,
        public array $options,
        public string $name,
        public ?string $sku,
        public int $quantity,
        public Money $unitPrice,
        public Money $total,
        public Money $tax,
        public bool $ships,
        public array $taken = [],
        public array $downloads = [],
        public ?int $downloadLimit = null,
        public ?int $downloadExpiryDays = null,
    ) {}
}
