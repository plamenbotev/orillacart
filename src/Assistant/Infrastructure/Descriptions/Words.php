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
        $name = trim($order->billing->firstName . ' ' . $order->billing->lastName);

        return "order #{$order->number} (" . ($order->anonymised ? 'made anonymous' : ($name === '' ? $order->email : "{$name}, {$order->email}")) . ')';
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

        return \is_string($value) ? trim($value) : (\is_int($value) || \is_float($value) ? (string) $value : '');
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
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return '"' . (mb_strlen($text) > $longest ? mb_substr($text, 0, $longest) . '…' : $text) . '"';
    }
}
