<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\AttributeSetView;
use OrillaCart\Catalog\Application\Dto\AttributeView;
use OrillaCart\Catalog\Application\Dto\OptionView;
use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveAttributeSet;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Domain\AttributeSetNotFound;
use OrillaCart\Extension\Catalog\AttributeDraft;
use OrillaCart\Extension\Catalog\AttributeSets;
use OrillaCart\Extension\Catalog\AttributeSetSummary;
use OrillaCart\Extension\Catalog\AttributeSummary;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Validation\CommandChecks;

/**
 * The shop's attribute sets as the extension API gives them to add-ons (design 69): read, made and changed as the
 * Attribute sets screen does, the commands checked as its requests are.
 */
final readonly class AddOnAttributeSets implements AttributeSets
{
    public function __construct(private AttributeSetService $sets, private CommandChecks $checks) {}

    public function all(): array
    {
        return array_map(self::summary(...), $this->sets->sets());
    }

    public function save(?int $id, string $name, array $attributes, bool $active = true): AttributeSetSummary
    {
        try {
            $existing = $id === null ? [] : $this->sets->set($id)->attributes;
        } catch (AttributeSetNotFound) {
            throw new \InvalidArgumentException(\sprintf('There is no attribute set %d.', $id));
        }
        $command = new SaveAttributeSet($name, $active, self::attributes($attributes, $existing));
        $this->checks->check($command);
        try {
            return self::summary($id === null ? $this->sets->create($command) : $this->sets->update($id, $command));
        } catch (RuleViolation $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    /**
     * The attributes to save from an add-on's, over those there are: one named as one there is keeps its id, its
     * settings an add-on does not name, and the ids of its options named alike.
     *
     * @param list<AttributeDraft> $drafts
     * @param list<AttributeView>  $existing
     *
     * @return list<SaveAttribute>
     */
    public static function attributes(array $drafts, array $existing): array
    {
        $byName = [];
        foreach ($existing as $attribute) {
            $byName[mb_strtolower($attribute->name)] = $attribute;
        }
        $saved = [];
        foreach ($drafts as $draft) {
            $was = $byName[mb_strtolower(trim($draft->name))] ?? null;
            $options = [];
            foreach ($was->options ?? [] as $option) {
                $options[mb_strtolower($option->name)] = $option;
            }
            $saved[] = new SaveAttribute(
                trim($draft->name),
                array_map(static function (string $name) use ($options): SaveOption {
                    $kept = $options[mb_strtolower(trim($name))] ?? null;

                    return new SaveOption(trim($name), $kept->adjustment ?? '0', $kept?->id, $kept->adjustmentKind ?? 'amount');
                }, $draft->options),
                $draft->required,
                $was->priceHidden ?? false,
                $was?->id,
                inSpecifications: $was->inSpecifications ?? true,
            );
        }

        return $saved;
    }

    public static function attribute(AttributeView $attribute): AttributeSummary
    {
        $options = [];
        foreach ($attribute->options as $option) {
            \assert($option instanceof OptionView);
            $options[$option->id] = $option->name;
        }

        return new AttributeSummary($attribute->id, $attribute->name, $attribute->required, $options);
    }

    private static function summary(AttributeSetView $set): AttributeSetSummary
    {
        return new AttributeSetSummary($set->id, $set->name, $set->active, array_map(self::attribute(...), $set->attributes));
    }
}
