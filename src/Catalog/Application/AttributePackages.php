<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Domain\Attribute;
use OrillaCart\Catalog\Domain\AttributeSet;
use OrillaCart\Catalog\Domain\AttributeSetRepository;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Translations\Application\Dto\PackageString;
use OrillaCart\Translations\Application\Dto\TranslationPackage;
use OrillaCart\Translations\Application\TranslationPackageSource;

/** An attribute set's words for the translation service: its attributes' names and their options'. */
final readonly class AttributePackages implements TranslationPackageSource
{
    public const string KIND = 'attribute_set';

    public function __construct(private AttributeSetRepository $sets) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function title(): string
    {
        return 'OrillaCart attribute set';
    }

    public function package(int $id): ?TranslationPackage
    {
        $set = $this->sets->find($id);

        return $set === null ? null : TranslationPackage::of(self::KIND, $id, $set->name(), self::strings($set->ownAttributes()));
    }

    public function ids(): iterable
    {
        return array_map(static fn(AttributeSet $set): int => $set->id(), $this->sets->all());
    }

    /**
     * The attributes' names and their options'.
     *
     * @param list<Attribute> $attributes
     *
     * @return list<PackageString>
     */
    public static function strings(array $attributes): array
    {
        $strings = [];
        foreach ($attributes as $attribute) {
            $strings[] = new PackageString(AttributeTranslations::TYPE, $attribute->id(), 'name', $attribute->name(), Text::of('Attribute %s', $attribute->name()));
            foreach ($attribute->options() as $option) {
                $strings[] = new PackageString(OptionTranslations::TYPE, $option->id(), 'name', $option->name(), Text::of('%1$s: %2$s', $attribute->name(), $option->name()));
            }
        }

        return $strings;
    }
}
