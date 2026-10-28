<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\NameTranslations;
use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Domain\Attribute;
use OrillaCart\Translations\Application\ContentTranslations;

/**
 * The translated names of a product's or an attribute set's attributes and options, saved with them (design 51):
 * a new attribute is translated in the save that makes it. Checked before the save, written once the save gave
 * the new ones their ids, and gone with the attributes and options the save removed.
 */
final readonly class AttributeTexts
{
    public function __construct(private ContentTranslations $translations) {}

    /**
     * @param list<Attribute> $attributes
     */
    public function of(array $attributes): NameTranslations
    {
        $options = [];
        foreach ($attributes as $attribute) {
            foreach ($attribute->options() as $option) {
                $options[] = $option->id();
            }
        }

        return new NameTranslations(
            self::names($this->translations->of(AttributeTranslations::TYPE, array_map(static fn(Attribute $attribute): int => $attribute->id(), $attributes))),
            self::names($this->translations->of(OptionTranslations::TYPE, $options)),
        );
    }

    /**
     * Checked before the save: a refused translation refuses it, before anything is written.
     *
     * @param list<SaveAttribute> $commands
     *
     * @return list<array{?array<string, array<string, string>>, list<?array<string, array<string, string>>>}> the
     *                                                                                                         attribute's and each option's, null for none sent
     */
    public function check(array $commands): array
    {
        return array_map(fn(SaveAttribute $command): array => [
            $command->translations === null ? null : $this->translations->check(AttributeTranslations::TYPE, ['name' => $command->translations]),
            array_map(fn(SaveOption $option): ?array => $option->translations === null ? null : $this->translations->check(OptionTranslations::TYPE, ['name' => $option->translations]), $command->options),
        ], $commands);
    }

    /**
     * Writes what check() returned once the save gave the new attributes and options their ids, and forgets those
     * of the ones it removed.
     *
     * @param list<array{?array<string, array<string, string>>, list<?array<string, array<string, string>>>}> $checked
     * @param list<Attribute>                                                                                  $saved            in the order sent
     * @param list<int>                                                                                        $attributesBefore
     * @param list<int>                                                                                        $optionsBefore
     */
    public function write(array $checked, array $saved, array $attributesBefore, array $optionsBefore): void
    {
        [$attributesNow, $optionsNow] = self::ids($saved);
        foreach ($saved as $i => $attribute) {
            [$name, $options] = $checked[$i] ?? [null, []];
            if ($name !== null) {
                $this->translations->write(AttributeTranslations::TYPE, $attribute->id(), $name);
            }
            foreach ($attribute->options() as $j => $option) {
                $translations = $options[$j] ?? null;
                if ($translations !== null) {
                    $this->translations->write(OptionTranslations::TYPE, $option->id(), $translations);
                }
            }
        }
        $this->forget(array_values(array_diff($attributesBefore, $attributesNow)), array_values(array_diff($optionsBefore, $optionsNow)));
    }

    /**
     * The attributes and options are gone.
     *
     * @param list<int> $attributeIds
     * @param list<int> $optionIds
     */
    public function forget(array $attributeIds, array $optionIds): void
    {
        $this->translations->forget(AttributeTranslations::TYPE, $attributeIds);
        $this->translations->forget(OptionTranslations::TYPE, $optionIds);
    }

    /**
     * @param list<Attribute> $attributes
     *
     * @return array{list<int>, list<int>} the attribute ids and the option ids
     */
    public static function ids(array $attributes): array
    {
        $options = [];
        foreach ($attributes as $attribute) {
            foreach ($attribute->options() as $option) {
                $options[] = $option->id();
            }
        }

        return [array_map(static fn(Attribute $attribute): int => $attribute->id(), $attributes), $options];
    }

    /**
     * @param array<int, array<string, array<string, string>>> $all
     *
     * @return array<int, array<string, string>>
     */
    private static function names(array $all): array
    {
        return array_map(static fn(array $fields): array => $fields['name'] ?? [], $all);
    }
}
