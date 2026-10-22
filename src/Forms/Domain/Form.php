<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Questions the shop asks of shoppers: on the product page, answered for the item bought (an engraving), or at
 * checkout, answered for the order (a purchase order number). It is asked for the products it is for.
 */
#[ORM\Entity]
#[ORM\Table(name: 'forms')]
class Form
{
    public const int MAX_NAME_LENGTH = 100;
    public const int MAX_FIELDS = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::MAX_NAME_LENGTH)]
    private string $name;

    #[ORM\Column(length: 20, enumType: FormPlacement::class)]
    private FormPlacement $placement;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(length: 20, enumType: FormScope::class)]
    private FormScope $scope = FormScope::All;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $categoryIds = [];

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $productIds = [];

    /** @var list<array<string, mixed>> FormField rows, in order */
    #[ORM\Column(type: 'json')]
    private array $fields = [];

    public function __construct(string $name, FormPlacement $placement)
    {
        $this->rename($name);
        $this->placement = $placement;
    }

    public function id(): int
    {
        return $this->id ?? throw new \LogicException('The form has no id until it is saved.');
    }

    public function name(): string
    {
        return $this->name;
    }

    public function placement(): FormPlacement
    {
        return $this->placement;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function scope(): FormScope
    {
        return $this->scope;
    }

    /** @return list<int> */
    public function categoryIds(): array
    {
        return $this->categoryIds;
    }

    /** @return list<int> */
    public function productIds(): array
    {
        return $this->productIds;
    }

    /** @return list<FormField> */
    public function fields(): array
    {
        return array_map(FormField::fromRow(...), $this->fields);
    }

    public function rename(string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new FormRuleViolation(\sprintf('A form needs a name of up to %d characters.', self::MAX_NAME_LENGTH), 'orillacart_invalid_form');
        }
        $this->name = $name;
    }

    public function activate(bool $active): void
    {
        $this->active = $active;
    }

    public function moveTo(int $position): void
    {
        $this->position = $position;
    }

    /**
     * @param list<int> $categoryIds for Categories
     * @param list<int> $productIds  for Products
     */
    public function aimAt(FormScope $scope, array $categoryIds, array $productIds): void
    {
        $categoryIds = array_values(array_unique(array_filter($categoryIds, static fn(int $id): bool => $id > 0)));
        $productIds = array_values(array_unique(array_filter($productIds, static fn(int $id): bool => $id > 0)));
        if (($scope === FormScope::Categories && $categoryIds === []) || ($scope === FormScope::Products && $productIds === [])) {
            throw new FormRuleViolation($scope === FormScope::Categories ? 'Choose the categories the form is for.' : 'Choose the products the form is for.', 'orillacart_invalid_form');
        }
        $this->scope = $scope;
        $this->categoryIds = $scope === FormScope::Categories ? $categoryIds : [];
        $this->productIds = $scope === FormScope::Products ? $productIds : [];
    }

    /**
     * Replaces the questions. Keys are unique; a condition looks at an earlier field, and at values it offers.
     * Only product forms charge for answers.
     *
     * @param list<FormField> $fields in order
     */
    public function replaceFields(array $fields): void
    {
        if (\count($fields) > self::MAX_FIELDS) {
            throw new FormRuleViolation(\sprintf('A form asks up to %d questions.', self::MAX_FIELDS), 'orillacart_invalid_form');
        }
        $earlier = [];
        foreach ($fields as $field) {
            if (isset($earlier[$field->key])) {
                throw new FormRuleViolation(\sprintf('Two fields have the key "%s".', $field->key), 'orillacart_invalid_form_field');
            }
            if ($this->placement === FormPlacement::Checkout && $field->hasPrice()) {
                throw new FormRuleViolation(\sprintf('"%s": questions at checkout have no price.', $field->label), 'orillacart_invalid_form_field');
            }
            if ($this->placement === FormPlacement::Checkout && $field->kind === FieldKind::File) {
                throw new FormRuleViolation(\sprintf('"%s": checkout does not take files.', $field->label), 'orillacart_invalid_form_field');
            }
            if ($field->condition !== null) {
                $other = $earlier[$field->condition->field] ?? throw new FormRuleViolation(\sprintf('"%s" is asked when a field above it is answered; choose one.', $field->label), 'orillacart_invalid_form_field');
                $offered = array_map(static fn(FieldChoice $choice): string => $choice->value, $other->choices);
                if ($field->condition->values !== [] && ($other->kind !== FieldKind::Choice || array_diff($field->condition->values, $offered) !== [])) {
                    throw new FormRuleViolation(\sprintf('"%s" is asked for values "%s" does not offer.', $field->label, $other->label), 'orillacart_invalid_form_field');
                }
            }
            $earlier[$field->key] = $field;
        }
        $this->fields = array_map(static fn(FormField $field): array => $field->toRow(), $fields);
    }

    /**
     * @param list<int> $categoryIds the product's, each with those above it
     */
    public function isFor(int $productId, array $categoryIds): bool
    {
        return match ($this->scope) {
            FormScope::All => true,
            FormScope::Categories => array_intersect($this->categoryIds, $categoryIds) !== [],
            FormScope::Products => \in_array($productId, $this->productIds, true),
        };
    }

    /** The answer's name outside the form: forms may share keys. */
    public function fieldId(string $key): string
    {
        return 'f' . $this->id() . '_' . $key;
    }
}
