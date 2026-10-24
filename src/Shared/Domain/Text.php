<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Domain;

/**
 * Words for a person, in English, with the values that go into them: "A coupon takes off at most %s." and the
 * amount. Kept apart so the words are translated where they leave the plugin (a REST answer, a page, an email)
 * and the values go in after; as a string it is the English sentence.
 */
final readonly class Text implements \Stringable
{
    /**
     * @param string                      $template English, with sprintf() placeholders for the values
     * @param list<int|float|string|self> $values   words of their own (a refused row's reason) are translated too
     */
    public function __construct(
        public string $template,
        public array $values = [],
    ) {}

    public static function of(string $template, int|float|string|\Stringable ...$values): self
    {
        return new self($template, array_values(array_map(static fn(int|float|string|\Stringable $value): int|float|string|self => $value instanceof \Stringable && !$value instanceof self ? (string) $value : $value, $values)));
    }

    public function __toString(): string
    {
        return self::format($this->template, $this->values);
    }

    /**
     * The template with the values in; the template as it is when it does not take them (a translation that lost
     * a placeholder).
     *
     * @param list<int|float|string|self> $values
     */
    public static function format(string $template, array $values): string
    {
        if ($values === []) {
            return $template;
        }
        try {
            return vsprintf($template, array_map(static fn(int|float|string|self $value): int|float|string => $value instanceof self ? (string) $value : $value, $values));
        } catch (\ValueError) {
            return $template;
        }
    }
}
