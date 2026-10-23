<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Application\Dto;

/**
 * The import schemes there are, with what the law sets, and those the shop sells under.
 */
final readonly class ImportSchemesView
{
    /**
     * @param list<array{scheme: string, label: string, legal_limit: string, currency: string, per_item: bool, example: string}> $known
     * @param list<array{scheme: string, number: ?string, limit: string}>                                                        $schemes
     */
    public function __construct(
        public string $shopCurrency,
        public array $known,
        public array $schemes,
    ) {}
}
