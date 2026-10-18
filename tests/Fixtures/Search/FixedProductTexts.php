<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Search;

use OrillaCart\Search\Application\Dto\ProductText;
use OrillaCart\Search\Application\ProductTexts;

/**
 * Every product is published, with its id as its title; one can be made to break.
 */
final class FixedProductTexts implements ProductTexts
{
    public ?int $broken = null;

    public function describe(array $productIds): array
    {
        $texts = [];
        foreach ($productIds as $id) {
            if ($id === $this->broken) {
                throw new \RuntimeException("Product {$id} is broken.");
            }
            $texts[$id] = new ProductText("Product {$id}", '', 0, new \DateTimeImmutable('2026-10-01'), [], []);
        }

        return $texts;
    }
}
