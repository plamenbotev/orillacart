<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\ProductSpecificationsView;
use OrillaCart\Catalog\Domain\AttributeOption;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Settings\Application\SettingsService;

/**
 * A product's specifications: the attributes it offers that the shop lists there (a product with variations
 * offers only the options they use), named in the shopper's language (design 51), and its weight and size.
 */
final readonly class ProductSpecifications
{
    public function __construct(
        private ProductRepository $products,
        private ProductTranslations $translations,
        private AttributeNames $names,
        private SettingsService $settings,
    ) {}

    /** Null for a product without commerce details; a translation's post gets its original's. */
    public function of(int $id): ?ProductSpecificationsView
    {
        $product = $this->products->find($this->translations->original($id));
        if ($product === null) {
            return null;
        }
        $this->names->warm($product);
        $attributes = [];
        foreach ($product->offeredOptions() as [$attribute, $options]) {
            if ($attribute->isInSpecifications()) {
                $attributes[] = [
                    'name' => $this->names->attribute($attribute),
                    'values' => array_map(fn(AttributeOption $option): string => $this->names->option($option), $options),
                ];
            }
        }
        $details = $product->details();
        $shop = $this->settings->current();

        return new ProductSpecificationsView(
            $attributes,
            $details->weight,
            $details->length,
            $details->width,
            $details->height,
            $shop->weightUnit,
            $shop->dimensionUnit,
            $details->type->needsShipping(),
            $details->shippingClassId,
        );
    }
}
