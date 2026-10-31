<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Domain;

/**
 * An attribute as the admin screen sends it, with all its options in order. An existing attribute keeps its id.
 */
final readonly class AttributeDraft
{
    public const int MAX_NAME_LENGTH = 100;

    /**
     * @param list<OptionDraft> $options in display order
     * @param bool              $priceHidden      the storefront shows the options without their price adjustments
     * @param bool              $inSpecifications the product page's specifications list it with its options
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public array $options,
        public bool $required = false,
        public bool $priceHidden = false,
        public bool $inSpecifications = true,
    ) {
        if (trim($name) === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new CatalogRuleViolation('An attribute needs a name of up to %d characters.', 'orillacart_invalid_attribute_name', values: [self::MAX_NAME_LENGTH]);
        }
        if ($options === []) {
            throw new CatalogRuleViolation('The attribute "%s" needs at least one option.', 'orillacart_attribute_without_options', values: [$name]);
        }
    }
}
