<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Domain;

/**
 * One of a choice field's values. Its value names it in answers and conditions; the label is what shoppers see.
 */
final readonly class FieldChoice
{
    public const int MAX_LABEL_LENGTH = 100;

    /**
     * @param string|null $price added to each item when chosen, in the shop's currency; product forms only
     */
    public function __construct(
        public string $value,
        public string $label,
        public ?string $price = null,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,39}$/', $value) !== 1) {
            throw new FormRuleViolation('"%s" is not a choice value: lowercase letters, digits, - and _, up to 40.', 'orillacart_invalid_form_field', values: [$value]);
        }
        if (trim($label) === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw new FormRuleViolation('Each choice needs a label of up to %d characters.', 'orillacart_invalid_form_field', values: [self::MAX_LABEL_LENGTH]);
        }
    }
}
