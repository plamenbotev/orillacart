<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Translations\Application\Dto\TranslationPackage;
use OrillaCart\Translations\Application\TranslationPackageSource;

/**
 * A product's own attributes and options for the translation service; its text is its post's, which the plugin
 * translates as a post (design 51).
 */
final readonly class ProductAttributePackages implements TranslationPackageSource
{
    public const string KIND = 'product';
    private const int PAGE = 200;

    public function __construct(
        private ProductRepository $products,
        private ProductContent $content,
    ) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function title(): string
    {
        return 'OrillaCart product attributes';
    }

    public function package(int $id): ?TranslationPackage
    {
        $product = $this->products->find($id);

        return $product === null ? null : TranslationPackage::of(self::KIND, $id, $this->content->name($id), AttributePackages::strings($product->ownAttributes()));
    }

    public function ids(): iterable
    {
        $after = 0;
        do {
            $ids = $this->products->ids($after, self::PAGE);
            yield from $ids;
            $after = $ids === [] ? $after : $ids[\count($ids) - 1];
        } while (\count($ids) === self::PAGE);
    }
}
