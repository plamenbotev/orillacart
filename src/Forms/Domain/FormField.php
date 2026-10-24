<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Domain;

use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\RuleViolation;

/**
 * A question of a form, kept in the form as a row.
 */
final readonly class FormField
{
    public const int MAX_LABEL_LENGTH = 100;
    public const int MAX_TEXT_LENGTH = 200;
    public const int MAX_HELP_LENGTH = 300;
    public const int MAX_LONGEST = 2000;
    public const int MAX_CHOICES = 50;

    public const int MAX_MEGABYTES = 20;
    /** What a file field may take, by the type its content shows; never SVG, which can carry scripts. */
    public const array FILE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];

    /** A choice shown as a drop-down list. */
    public const string LIST = 'list';
    /** A choice shown as buttons, one per value. */
    public const string BUTTONS = 'buttons';

    /**
     * @param string            $key     lowercase letters, digits and underscores, up to 30: names the answer
     * @param list<FieldChoice> $choices for a choice, in the order to show
     * @param string|null       $price   added to each item when answered (a choice's own prices instead); product forms only
     * @param string|null       $min     a number's lowest
     * @param string|null       $max     a number's highest
     * @param bool              $whole   a number without decimals
     * @param list<string>      $types   the file types a file field takes, of FILE_TYPES
     * @param int               $largest a file's largest size in megabytes
     */
    public function __construct(
        public string $key,
        public string $label,
        public FieldKind $kind = FieldKind::Text,
        public bool $required = false,
        public string $help = '',
        public string $placeholder = '',
        public int $longest = self::MAX_TEXT_LENGTH,
        public array $choices = [],
        public string $display = self::LIST,
        public ?string $price = null,
        public ?string $min = null,
        public ?string $max = null,
        public bool $whole = false,
        public FieldSection $section = FieldSection::Order,
        public ?FieldCondition $condition = null,
        public array $types = self::FILE_TYPES,
        public int $largest = 10,
    ) {
        $refuse = static fn(string $message, int $limit = 0): FormRuleViolation => new FormRuleViolation($message, 'orillacart_invalid_form_field', values: [$label, $limit]);
        if (preg_match('/^[a-z][a-z0-9_]{0,29}$/', $key) !== 1) {
            throw new FormRuleViolation('"%s" is not a field key: lowercase letters, digits and underscores, up to 30, starting with a letter.', 'orillacart_invalid_form_field', values: [$key]);
        }
        if (trim($label) === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw new FormRuleViolation('Each field needs a label of up to %d characters.', 'orillacart_invalid_form_field', values: [self::MAX_LABEL_LENGTH]);
        }
        if (mb_strlen($help) > self::MAX_HELP_LENGTH || mb_strlen($placeholder) > self::MAX_LABEL_LENGTH) {
            throw $refuse('The help or placeholder of "%s" is too long.');
        }
        if ($longest < 1 || $longest > self::MAX_LONGEST) {
            throw $refuse('"%1$s" takes from 1 to %2$d characters.', self::MAX_LONGEST);
        }
        if ($kind === FieldKind::Choice && $choices === []) {
            throw $refuse('"%s" offers no choices.');
        }
        if (\count($choices) > self::MAX_CHOICES) {
            throw $refuse('"%1$s" offers more than %2$d choices.', self::MAX_CHOICES);
        }
        if ($kind === FieldKind::File && ($types === [] || array_diff($types, self::FILE_TYPES) !== [] || $largest < 1 || $largest > self::MAX_MEGABYTES)) {
            throw $refuse('"%1$s" takes some of JPEG, PNG, WebP, GIF and PDF, up to %2$d MB.', self::MAX_MEGABYTES);
        }
        if (!\in_array($display, [self::LIST, self::BUTTONS], true)) {
            throw $refuse('"%s" is shown as a list or as buttons.');
        }
        $values = array_map(static fn(FieldChoice $choice): string => $choice->value, $choices);
        if (\count(array_unique($values)) !== \count($values)) {
            throw $refuse('Two choices of "%s" have the same value.');
        }
        foreach ([$price, ...array_map(static fn(FieldChoice $choice): ?string => $choice->price, $choices)] as $amount) {
            if ($amount !== null && self::amount($amount)->isNegative()) {
                throw $refuse('The prices of "%s" cannot be below zero.');
            }
        }
        if ($kind === FieldKind::Number) {
            $low = $min === null ? null : self::amount($min);
            $high = $max === null ? null : self::amount($max);
            if ($low !== null && $high !== null && $low->compare($high) > 0) {
                throw $refuse('The lowest number of "%s" is above its highest.');
            }
        }
    }

    /** What an item adds when the field has this answer: the choice's price, or the field's own. */
    public function priceOf(string $answer): ?string
    {
        if ($this->kind !== FieldKind::Choice) {
            return $this->price;
        }
        foreach ($this->choices as $choice) {
            if ($choice->value === $answer) {
                return $choice->price;
            }
        }

        return null;
    }

    public function hasPrice(): bool
    {
        return $this->price !== null || array_filter($this->choices, static fn(FieldChoice $choice): bool => $choice->price !== null) !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'kind' => $this->kind->value,
            'required' => $this->required,
            'help' => $this->help,
            'placeholder' => $this->placeholder,
            'longest' => $this->longest,
            'choices' => array_map(static fn(FieldChoice $choice): array => ['value' => $choice->value, 'label' => $choice->label, 'price' => $choice->price], $this->choices),
            'display' => $this->display,
            'price' => $this->price,
            'min' => $this->min,
            'max' => $this->max,
            'whole' => $this->whole,
            'section' => $this->section->value,
            'condition' => $this->condition === null ? null : ['field' => $this->condition->field, 'values' => $this->condition->values],
            'types' => $this->types,
            'largest' => $this->largest,
        ];
    }

    /**
     * @param array<string, mixed> $row as toRow() gave it
     */
    public static function fromRow(array $row): self
    {
        /** @var array{key: string, label: string, kind: string, required: bool, help: string, placeholder: string, longest: int, choices: list<array{value: string, label: string, price: ?string}>, display: string, price: ?string, min: ?string, max: ?string, whole: bool, section: string, condition: ?array{field: string, values: list<string>}, types: list<string>, largest: int} $row */
        return new self(
            $row['key'],
            $row['label'],
            FieldKind::from($row['kind']),
            $row['required'],
            $row['help'],
            $row['placeholder'],
            $row['longest'],
            array_map(static fn(array $choice): FieldChoice => new FieldChoice($choice['value'], $choice['label'], $choice['price']), $row['choices']),
            $row['display'],
            $row['price'],
            $row['min'],
            $row['max'],
            $row['whole'],
            FieldSection::from($row['section']),
            $row['condition'] === null ? null : new FieldCondition($row['condition']['field'], $row['condition']['values']),
            $row['types'],
            $row['largest'],
        );
    }

    private static function amount(string $value): Money
    {
        try {
            return Money::of($value);
        } catch (RuleViolation) {
            throw new FormRuleViolation('"%s" is not an amount.', 'orillacart_invalid_form_field', values: [$value]);
        }
    }
}
