<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Extension;

use OrillaCart\Extension\Setting;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Security\SecretKeys;

/**
 * Checks what the admin screen sends for a gateway's or shipping method's settings against what it declares,
 * keeping the saved secrets the screen never receives, and shows saved values without them. A secret sent empty
 * stays as saved, unless a setting that says where it goes (an address, a user: SecretKeys::endpoint()) changed;
 * sent as false, it is cleared.
 */
final class SettingValues
{
    /** The longest text a setting holds. */
    public const int MAX_LENGTH = 2000;

    /**
     * @param list<Setting>              $fields
     * @param array<string, string|bool> $saved
     * @param array<string, string|bool> $submitted
     *
     * @return array<string, string|bool> every declared setting, in their order
     *
     * @throws RuleViolation for an undeclared setting, or a value of the wrong kind
     */
    public static function accept(array $fields, array $saved, array $submitted, string $owner): array
    {
        $byKey = [];
        foreach ($fields as $field) {
            $byKey[$field->key] = $field;
        }
        foreach (array_keys($submitted) as $key) {
            if (!isset($byKey[$key])) {
                throw new RuleViolation('"%1$s" is not a setting of %2$s.', 'orillacart_unknown_setting', values: [$key, $owner]);
            }
        }
        SecretKeys::declare(...array_keys(array_filter($byKey, static fn(Setting $field): bool => $field->type === Setting::SECRET)));
        $values = [];
        $moved = false;
        foreach ($byKey as $key => $field) {
            if ($field->type === Setting::SECRET) {
                continue;
            }
            $values[$key] = self::value($field, $submitted[$key] ?? null, $saved[$key] ?? null);
            $was = $saved[$key] ?? null;
            if (\is_string($values[$key]) && (SecretKeys::endpoint($key, $values[$key]) || SecretKeys::endpoint($key, $was)) && self::address($values[$key]) !== self::address(\is_string($was) ? $was : '')) {
                $moved = true;
            }
        }
        $ordered = [];
        foreach ($byKey as $key => $field) {
            // A saved secret goes only where it was typed for: a new address or user forgets it unless typed again.
            $ordered[$key] = $field->type === Setting::SECRET ? self::value($field, $submitted[$key] ?? null, $moved ? null : ($saved[$key] ?? null)) : $values[$key];
        }

        return $ordered;
    }

    /**
     * The first required setting without a value, which keeps the owner from being turned on.
     *
     * @param list<Setting>              $fields
     * @param array<string, string|bool> $values
     */
    public static function missing(array $fields, array $values): ?Setting
    {
        foreach ($fields as $field) {
            if ($field->required && ($values[$field->key] ?? '') === '') {
                return $field;
            }
        }

        return null;
    }

    /**
     * The values as the admin screen may see them: secrets empty, with the keys of the saved ones apart.
     *
     * @param list<Setting>              $fields
     * @param array<string, string|bool> $saved
     *
     * @return array{array<string, string|bool>, list<string>}
     */
    public static function shown(array $fields, array $saved): array
    {
        $values = [];
        $secretsSet = [];
        SecretKeys::declare(...array_map(static fn(Setting $field): string => $field->key, array_values(array_filter($fields, static fn(Setting $field): bool => $field->type === Setting::SECRET))));
        foreach ($fields as $field) {
            $value = $saved[$field->key] ?? ($field->type === Setting::BOOLEAN ? false : '');
            if ($field->type === Setting::SECRET) {
                if ($value !== '') {
                    $secretsSet[] = $field->key;
                }
                $value = '';
            }
            $values[$field->key] = $value;
        }

        return [$values, $secretsSet];
    }

    private static function address(string $value): string
    {
        return rtrim(mb_strtolower(trim($value)), '/');
    }

    private static function value(Setting $field, string|bool|null $value, string|bool|null $saved): string|bool
    {
        if ($field->type === Setting::BOOLEAN) {
            if ($value !== null && !\is_bool($value)) {
                throw new RuleViolation('"%s" is on or off.', 'orillacart_invalid_setting', values: [$field->label]);
            }

            return $value ?? false;
        }
        if ($field->type === Setting::SECRET && $value === false) {
            return '';
        }
        if ($value !== null && !\is_string($value)) {
            throw new RuleViolation('"%s" is text.', 'orillacart_invalid_setting', values: [$field->label]);
        }
        $value = trim($value ?? '');
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new RuleViolation('"%1$s" has up to %2$d characters.', 'orillacart_invalid_setting', values: [$field->label, self::MAX_LENGTH]);
        }
        if ($field->type === Setting::NUMBER && $value !== '' && preg_match('/^-?\d{1,12}(\.\d{1,6})?$/', $value) !== 1) {
            throw new RuleViolation('"%s" is a number, such as 2.5.', 'orillacart_invalid_setting', values: [$field->label]);
        }
        if ($field->type === Setting::CHOICE && $value !== '' && !\array_key_exists($value, $field->choices)) {
            throw new RuleViolation('"%1$s" is one of: %2$s.', 'orillacart_invalid_setting', values: [$field->label, implode(', ', array_map(strval(...), array_keys($field->choices)))]);
        }

        // An empty secret keeps the saved one: the screen never receives secrets to send back.
        return $field->type === Setting::SECRET && $value === '' ? (\is_string($saved) ? $saved : '') : $value;
    }
}
