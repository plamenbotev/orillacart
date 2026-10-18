<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Extension;

use OrillaCart\Extension\Setting;
use OrillaCart\Shared\Domain\RuleViolation;

/**
 * Checks what the admin screen sends for a gateway's or shipping method's settings against what it declares,
 * keeping the saved secrets the screen never receives, and shows saved values without them.
 */
final class SettingValues
{
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
                throw new RuleViolation(\sprintf('"%s" is not a setting of %s.', $key, $owner), 'orillacart_unknown_setting');
            }
        }
        $values = [];
        foreach ($byKey as $key => $field) {
            $values[$key] = self::value($field, $submitted[$key] ?? null, $saved[$key] ?? null);
        }

        return $values;
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

    private static function value(Setting $field, string|bool|null $value, string|bool|null $saved): string|bool
    {
        if ($field->type === Setting::BOOLEAN) {
            if ($value !== null && !\is_bool($value)) {
                throw new RuleViolation(\sprintf('"%s" is on or off.', $field->label), 'orillacart_invalid_setting');
            }

            return $value ?? false;
        }
        if ($value !== null && !\is_string($value)) {
            throw new RuleViolation(\sprintf('"%s" is text.', $field->label), 'orillacart_invalid_setting');
        }
        $value = trim($value ?? '');
        if ($field->type === Setting::NUMBER && $value !== '' && preg_match('/^-?\d{1,12}(\.\d{1,6})?$/', $value) !== 1) {
            throw new RuleViolation(\sprintf('"%s" is a number, such as 2.5.', $field->label), 'orillacart_invalid_setting');
        }
        if ($field->type === Setting::CHOICE && $value !== '' && !\array_key_exists($value, $field->choices)) {
            throw new RuleViolation(\sprintf('"%s" is one of: %s.', $field->label, implode(', ', $field->choices)), 'orillacart_invalid_setting');
        }

        // An empty secret keeps the saved one: the screen never receives secrets to send back.
        return $field->type === Setting::SECRET && $value === '' ? (\is_string($saved) ? $saved : '') : $value;
    }
}
