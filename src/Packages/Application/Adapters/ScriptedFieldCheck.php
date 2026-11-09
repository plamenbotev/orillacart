<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Extension\Checkout\CheckoutField;
use OrillaCart\Extension\Checkout\FieldCheck;
use OrillaCart\Extension\Checkout\FieldContext;
use OrillaCart\Packages\Application\Values;
use OrillaCart\Packages\Domain\HandlerFailed;

/**
 * `checkout_field "id" { label, kind, section, required, choices, help, longest; fn check(answer, context) }`:
 * the field as the add-on API declares it, with the script's check of an answer when it has one.
 */
final class ScriptedFieldCheck extends ScriptedProvider implements FieldCheck
{
    public function field(): CheckoutField
    {
        $props = $this->declaration['props'];
        $choices = [];
        foreach (\is_array($props['choices'] ?? null) ? $props['choices'] : [] as $value => $label) {
            $choices[\is_int($value) ? Values::text($label) : $value] = $this->loaded->word(Values::text($label));
        }

        return new CheckoutField(
            $this->id(),
            \is_string($props['label'] ?? null) ? $this->loaded->word($props['label']) : $this->id(),
            self::word($props['kind'] ?? null, CheckoutField::TEXT),
            self::word($props['section'] ?? null, CheckoutField::BILLING),
            ($props['required'] ?? false) === true,
            $choices,
            \is_string($props['help'] ?? null) ? $this->loaded->word($props['help']) : '',
            \is_int($props['longest'] ?? null) ? $props['longest'] : 200,
            $this->has('check') ? $this : null,
        );
    }

    public function check(string $answer, FieldContext $context): ?string
    {
        try {
            $answer = $this->call('check', [$answer, Values::record($context)]);
        } catch (HandlerFailed $e) {
            return $e->getMessage();
        }

        return $answer === null || $answer === true ? null : Values::text($answer);
    }

    /** A declared word (`kind: choice`) or text, else the default. */
    private static function word(mixed $value, string $default): string
    {
        if (\is_array($value) && \is_string($value['$spec'] ?? null)) {
            return $value['$spec'];
        }

        return \is_string($value) ? $value : $default;
    }
}
