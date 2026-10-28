<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Domain\Attribute;
use OrillaCart\Catalog\Domain\AttributeOption;
use OrillaCart\Catalog\Domain\Product;
use OrillaCart\Translations\Application\ContentTranslations;

/**
 * Attributes' and options' names as the shopper reads them (design 51): in the language shown when translated,
 * else their own. A product's are read together, and kept for the request in each language asked.
 */
final class AttributeNames
{
    /** @var array<string, array<string, array<int, ?string>>> the translation or null, by locale, type and id */
    private array $known = [];

    public function __construct(private readonly ContentTranslations $translations) {}

    /** Reads the names of the products' attributes and options at once, rather than each when asked. */
    public function warm(Product ...$products): void
    {
        $locale = $this->translations->shownLocale();
        if ($locale === null) {
            return;
        }
        $attributes = [];
        $options = [];
        foreach ($products as $product) {
            foreach ($product->offeredOptions() as [$attribute, $offered]) {
                $attributes[] = $attribute->id();
                foreach ($offered as $option) {
                    $options[] = $option->id();
                }
            }
        }
        $this->load($locale, AttributeTranslations::TYPE, $attributes);
        $this->load($locale, OptionTranslations::TYPE, $options);
    }

    public function attribute(Attribute $attribute, ?string $locale = null): string
    {
        return $this->name(AttributeTranslations::TYPE, $attribute->id(), $locale) ?? $attribute->name();
    }

    public function option(AttributeOption $option, ?string $locale = null): string
    {
        return $this->name(OptionTranslations::TYPE, $option->id(), $locale) ?? $option->name();
    }

    private function name(string $type, int $id, ?string $locale): ?string
    {
        $locale = $this->translations->shownLocale($locale);
        if ($locale === null) {
            return null;
        }
        if (!\array_key_exists($id, $this->known[$locale][$type] ?? [])) {
            $this->load($locale, $type, [$id]);
        }

        return $this->known[$locale][$type][$id] ?? null;
    }

    /**
     * @param list<int> $ids
     */
    private function load(string $locale, string $type, array $ids): void
    {
        $missing = array_values(array_filter(array_unique($ids), fn(int $id): bool => !\array_key_exists($id, $this->known[$locale][$type] ?? [])));
        if ($missing === []) {
            return;
        }
        $found = $this->translations->translated($type, $missing, 'name', $locale);
        foreach ($missing as $id) {
            $this->known[$locale][$type][$id] = $found[$id] ?? null;
        }
    }
}
