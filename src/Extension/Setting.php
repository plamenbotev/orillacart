<?php

declare(strict_types=1);

namespace OrillaCart\Extension;

/**
 * One setting a gateway or shipping method needs, as the admin screen shows it. The shop stores the values and
 * hands them back as Settings. Secrets are never sent back to the browser: the screen shows only whether one is
 * saved, and an empty field keeps it. A secret says which settings tell where it goes or whose it is ($goesWith:
 * an address, a user name): when one of those changes, the saved secret is forgotten unless typed again, so it is
 * never sent somewhere it was not typed for. A secret that says nothing goes with every other setting.
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
     * A block's settings only (API 28; elsewhere left out): an image of the media library, given as
     * { id, url, alt, width, height }.
     */
    public const string IMAGE = 'image';
    /** A block's: an address, http(s), mailto:, tel:, a path or #fragment; any other is left out. */
    public const string LINK = 'link';
    /** A block's: a colour, "#1e40af". */
    public const string COLOR = 'color';
    /** A block's: a published product's id. */
    public const string PRODUCT = 'product';
    /** A block's: a product category's id. */
    public const string CATEGORY = 'category';
    /** A block's: formatted text, as HTML a post may hold. */
    public const string RICH_TEXT = 'rich_text';
    /** A block's: items, each a map of $fields (none a list or a secret): slides, cards, tabs. */
    public const string LIST = 'list';
    /** The types only a block's settings have (API 28). */
    public const array BLOCK_TYPES = [self::IMAGE, self::LINK, self::COLOR, self::PRODUCT, self::CATEGORY, self::RICH_TEXT, self::LIST];

    /**
     * @param array<string, string> $choices  for CHOICE: value => label, in the order to show
     * @param list<string>|null     $goesWith for SECRET: the keys of the settings that say where it goes or whose
     *                                        it is; [] for a secret that always goes to the same place; null for
     *                                        every other setting
     * @param list<Setting>         $fields   for LIST: what each item has
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type = self::TEXT,
        public bool $required = false,
        public string $help = '',
        public array $choices = [],
        public ?array $goesWith = null,
        public array $fields = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a setting key: lowercase letters, digits and underscores.', $key));
        }
        if (!\in_array($type, [self::TEXT, self::SECRET, self::BOOLEAN, self::NUMBER, self::CHOICE, ...self::BLOCK_TYPES], true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a setting type.', $type));
        }
        if ($type === self::CHOICE && $choices === []) {
            throw new \InvalidArgumentException(\sprintf('The setting "%s" offers no choices.', $key));
        }
        if ($type === self::LIST && $fields === []) {
            throw new \InvalidArgumentException(\sprintf('The list "%s" says what its items have: its fields.', $key));
        }
        if ($fields !== [] && $type !== self::LIST) {
            throw new \InvalidArgumentException(\sprintf('Only a list has fields, not the setting "%s".', $key));
        }
        foreach ($fields as $field) {
            if (!$field instanceof self || $field->type === self::LIST || $field->type === self::SECRET) {
                throw new \InvalidArgumentException(\sprintf('The list "%s" has settings for fields, none a list or a secret.', $key));
            }
        }
        if ($goesWith !== null && $type !== self::SECRET) {
            throw new \InvalidArgumentException(\sprintf('Only a secret says what it goes with, not the setting "%s".', $key));
        }
        foreach ($goesWith ?? [] as $other) {
            if (!\is_string($other) || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $other) !== 1 || $other === $key) {
                throw new \InvalidArgumentException(\sprintf('The secret "%s" goes with settings named by their keys.', $key));
            }
        }
    }
}
