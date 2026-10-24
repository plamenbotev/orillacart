<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

/**
 * The answer to a question checkout asked for an add-on, kept with the question as it was then.
 */
final readonly class FieldAnswer
{
    public const int LONGEST = 2000;

    public function __construct(
        public string $id,
        public string $label,
        public string $answer,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $id) !== 1) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a field id.', $id));
        }
        if (mb_strlen($answer) > self::LONGEST) {
            throw new OrderRuleViolation('%1$s has up to %2$d characters.', 'orillacart_invalid_field', values: [$label, self::LONGEST]);
        }
    }
}
