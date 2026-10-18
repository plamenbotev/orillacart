<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SaveSale;
use OrillaCart\Catalog\Domain\AdjustmentKind;
use OrillaCart\Catalog\Domain\AttributeDraft;
use OrillaCart\Catalog\Domain\OptionDraft;
use OrillaCart\Catalog\Domain\Pricing;
use OrillaCart\Catalog\Domain\Sale;
use OrillaCart\Shared\Domain\Money;

/**
 * Turns command DTOs into the domain's value objects.
 */
final class CatalogDrafts
{
    /**
     * @param list<SaveAttribute> $attributes
     *
     * @return list<AttributeDraft>
     */
    public static function attributes(array $attributes): array
    {
        return array_map(static fn(SaveAttribute $attribute): AttributeDraft => new AttributeDraft(
            $attribute->id,
            $attribute->name,
            array_map(static fn(SaveOption $option): OptionDraft => new OptionDraft($option->id, $option->name, Money::of($option->adjustment), AdjustmentKind::from($option->adjustmentKind)), $attribute->options),
            $attribute->required,
            $attribute->priceHidden,
        ), $attributes);
    }

    public static function pricing(string $price, ?SaveSale $sale): Pricing
    {
        return new Pricing(Money::of($price), $sale === null ? null : new Sale(Money::of($sale->price), $sale->from, $sale->until));
    }
}
