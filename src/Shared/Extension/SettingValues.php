<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Extension;

use OrillaCart\Extension\Setting;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Security\SecretKeys;

/**
 * Checks what the admin screen sends for a gateway's or shipping method's settings against what it declares,
 * keeping the saved secrets the screen never receives, and shows saved values without them. A secret sent empty
 * stays as saved, unless a setting it goes with (Setting::$goesWith, every other setting when it declares none)
 * changed; sent as false, it is cleared.
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
        SecretKeys::declarePlain(...array_keys(array_filter($byKey, static fn(Setting $field): bool => $field->type !== Setting::SECRET)));
        $values = [];
        $changed = [];
        foreach ($byKey as $key => $field) {
            if ($field->type === Setting::SECRET) {
                continue;
            }
            $values[$key] = self::value($field, $submitted[$key] ?? null, $saved[$key] ?? null);
            if (self::comparable($values[$key]) !== self::comparable($saved[$key] ?? null)) {
                $changed[$key] = true;
            }
        }
        $ordered = [];
        foreach ($byKey as $key => $field) {
            if ($field->type !== Setting::SECRET) {
                $ordered[$key] = $values[$key];
                continue;
            }
            // A saved secret goes only where it was typed for: a change to what it goes with forgets it unless
            // typed again. One that declares nothing goes with every other setting.
            $moved = array_any($field->goesWith ?? array_keys($values), static fn(string $other): bool => isset($changed[$other]));
            $ordered[$key] = self::value($field, $submitted[$key] ?? null, $moved ? null : ($saved[$key] ?? null));
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

    /** A value as compared for a change: text without case, space or a trailing slash; unset as empty. */
    private static function comparable(string|bool|null $value): string|bool
    {
        return \is_bool($value) ? $value : rtrim(mb_strtolower(trim($value ?? '')), '/');
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
