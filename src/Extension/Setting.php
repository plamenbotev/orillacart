<?php

declare(strict_types=1);

namespace OrillaCart\Extension;

/**
 * One setting a gateway or shipping method needs, as the admin screen shows it. The shop stores the values and
 * hands them back as Settings. Secrets are never sent back to the browser: the screen shows only whether one is
 * saved, and an empty field keeps it.
 */
final readonly class Setting
{
    public const string TEXT = 'text';
    public const string SECRET = 'secret';
    public const string BOOLEAN = 'boolean';
    /** A decimal number, kept as text: "2.5". */
    public const string NUMBER = 'number';
    /** One of $choices. */
    public const string CHOICE = 'choice';

    /**
     * @param array<string, string> $choices for CHOICE: value => label, in the order to show
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = self::TEXT,
        public bool $required = false,
        public string $help = '',
        public array $choices = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a setting key: lowercase letters, digits and underscores.', $key));
        }
        if (!\in_array($type, [self::TEXT, self::SECRET, self::BOOLEAN, self::NUMBER, self::CHOICE], true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a setting type.', $type));
        }
        if ($type === self::CHOICE && $choices === []) {
            throw new \InvalidArgumentException(\sprintf('The setting "%s" offers no choices.', $key));
        }
    }
}
