<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\Descriptions;

use OrillaCart\Ordering\Application\Dto\OrderView;

/**
 * The pieces descriptions share: an order as the owner knows it, an argument read as what it is.
 */
final class Words
{
    /** "order #1042 (Jane Doe, jane@example.com)". */
    public static function order(OrderView $order): string
    {
        $name = self::plain($order->billing->firstName . ' ' . $order->billing->lastName);
        $email = self::plain($order->email);

        return "order #{$order->number} (" . ($order->anonymised ? 'made anonymous' : ($name === '' ? $email : "{$name}, {$email}")) . ')';
    }

    /**
     * A customer's or a model's words on one line of the shop's own: line breaks, tabs and invisible characters
     * become spaces, so a name cannot start a line that reads as the shop's.
     */
    public static function plain(string $text): string
    {
        return trim((string) preg_replace('/[\s\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $text));
    }

    /**
     * An address given as arguments, on one line: "Jane Doe, 1 Vitosha Blvd, 1000 Sofia, BG, +359 2 123".
     *
     * @param array<array-key, mixed> $address
     */
    public static function address(array $address): string
    {
        $part = static fn(string $key): string => \is_string($address[$key] ?? null) ? self::plain($address[$key]) : '';
        $parts = [
            trim($part('first_name') . ' ' . $part('last_name')),
            $part('street'),
            trim($part('postcode') . ' ' . $part('city')),
            trim($part('state') . ' ' . $part('country')),
            $part('phone'),
        ];

        return implode(', ', array_filter($parts, static fn(string $piece): bool => $piece !== ''));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function int(array $arguments, string $name): ?int
    {
        $value = $arguments[$name] ?? null;

        return \is_int($value) ? $value : (\is_string($value) && ctype_digit($value) ? (int) $value : null);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function string(array $arguments, string $name): string
    {
        $value = $arguments[$name] ?? null;

        return \is_string($value) ? self::plain($value) : (\is_int($value) || \is_float($value) ? (string) $value : '');
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function yes(array $arguments, string $name): bool
    {
        return ($arguments[$name] ?? false) === true;
    }

    /** A text the owner reads in full, unless it is long. */
    public static function quoted(string $text, int $longest = 600): string
    {
        $text = self::plain($text);

        return '"' . (mb_strlen($text) > $longest ? mb_substr($text, 0, $longest) . '…' : $text) . '"';
    }
}
