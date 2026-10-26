<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Routing;

/**
 * A field's validation rules in words, for people and models reading what a route takes: "at most 100
 * characters", "one of read, read_write".
 */
final class RuleWords
{
    private const string SNAKE = '/(?<!^)[A-Z]/';

    /**
     * A validation attribute in words: "at most 100 characters", "one of read, read_write".
     *
     * @param \ReflectionAttribute<object> $attribute
     */
    public static function of(\ReflectionAttribute $attribute): string
    {
        $class = $attribute->getName();
        $slash = strrpos($class, '\\');
        $name = $slash === false ? $class : substr($class, $slash + 1);
        $args = $attribute->getArguments();
        $arg = static fn(string $key, int $position = 0): mixed => $args[$key] ?? $args[$position] ?? null;

        return match ($name) {
            'NotBlank' => 'not empty',
            'Positive' => 'more than 0',
            'PositiveOrZero' => '0 or more',
            'Email' => 'an email',
            'Url' => 'an address (URL)',
            'Date' => 'a date, Y-m-d',
            'Length' => self::range($arg('min'), $arg('max'), 'characters'),
            'Count' => self::range($arg('min'), $arg('max'), 'items'),
            'Range' => self::range($arg('min'), $arg('max'), ''),
            'Choice' => 'one of ' . self::listed(self::choices($arg('choices'), $args['callback'] ?? null)),
            'All' => self::each($arg('constraints')),
            'Regex' => \is_string($arg('pattern')) ? 'matches `' . $arg('pattern') . '`' : '',
            'Valid', 'Type' => '',
            default => strtolower((string) preg_replace(self::SNAKE, ' $0', $name)),
        };
    }

    /**
     * The values of a Choice: given, or what its callback answers.
     *
     * @return list<mixed>
     */
    private static function choices(mixed $choices, mixed $callback): array
    {
        if (\is_array($choices)) {
            return array_values($choices);
        }
        $values = \is_callable($callback) ? $callback() : null;

        return \is_array($values) ? array_values($values) : ['the known values'];
    }

    /**
     * @param list<mixed> $values
     */
    private static function listed(array $values): string
    {
        return implode(', ', array_map(static fn(mixed $value): string => \is_scalar($value) ? (string) $value : '', $values));
    }

    /** What each item of a list must be, from an All's constraints. */
    private static function each(mixed $constraints): string
    {
        $words = [];
        foreach (\is_array($constraints) ? $constraints : [$constraints] as $constraint) {
            $words[] = match (true) {
                $constraint instanceof \Symfony\Component\Validator\Constraints\Positive => 'more than 0',
                $constraint instanceof \Symfony\Component\Validator\Constraints\NotBlank => 'not empty',
                $constraint instanceof \Symfony\Component\Validator\Constraints\Length => self::range($constraint->min, $constraint->max, 'characters'),
                $constraint instanceof \Symfony\Component\Validator\Constraints\Choice => 'one of ' . self::listed(self::choices($constraint->choices, $constraint->callback)),
                default => '',
            };
        }
        $words = array_filter($words, static fn(string $word): bool => $word !== '');

        return $words === [] ? '' : 'each ' . implode(', ', $words);
    }

    private static function range(mixed $min, mixed $max, string $unit): string
    {
        $unit = $unit === '' ? '' : " {$unit}";

        return match (true) {
            \is_scalar($min) && \is_scalar($max) => "{$min} to {$max}{$unit}",
            \is_scalar($max) => "at most {$max}{$unit}",
            \is_scalar($min) => "at least {$min}{$unit}",
            default => '',
        };
    }
}
