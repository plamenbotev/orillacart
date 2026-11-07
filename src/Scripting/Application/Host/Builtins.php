<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application\Host;

use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Scripting\Domain\ScriptError;
use OrillaCart\Scripting\Domain\ScriptFailure;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Domain\RuleViolation;

/**
 * The functions every script has without a namespace: values (money, the error values `fail` takes), sizes,
 * and JSON.
 */
final readonly class Builtins implements HostNamespace
{
    public function functions(): array
    {
        return [
            new HostFunction('money', static function (Context $c, mixed $amount, mixed $currency = null): Money {
                if ($currency !== null && preg_match('/^[A-Z]{3}$/', Ops::text($currency)) !== 1) {
                    throw new ScriptError(\sprintf('"%s" is not a currency code such as EUR', Ops::text($currency)));
                }
                try {
                    return $amount instanceof Money ? $amount : Money::of(Ops::text($amount));
                } catch (RuleViolation $e) {
                    throw new ScriptError($e->getMessage());
                }
            }, 1, 2, 'money(amount, currency?): a money value from a number or a decimal string, exact to four decimals; amounts are in the shop\'s currency unless the host says otherwise'),
            new HostFunction('unavailable', self::errorValue(ScriptFailure::UNAVAILABLE), 1, 2, 'unavailable(message, data?): the error value of a provider that cannot be reached; the shop tries again later'),
            new HostFunction('refused', self::errorValue(ScriptFailure::REFUSED), 1, 2, 'refused(message, data?): the error value of an answer to show whoever asked'),
            new HostFunction('error', self::errorValue(ScriptFailure::ERROR), 1, 2, 'error(message, data?): the error value of a problem to log'),
            new HostFunction('len', static fn(Context $c, mixed $value): int => \is_array($value) ? \count($value) : mb_strlen(Ops::text($value)), 1, 1, 'len(value): how many items a list or map has, or characters a string'),
            new HostFunction('keys', static fn(Context $c, mixed $value): array => array_keys(Ops::iterable($value)), 1, 1, 'keys(map): its keys as a list'),
            new HostFunction('values', static fn(Context $c, mixed $value): array => array_values(Ops::iterable($value)), 1, 1, 'values(map): its values as a list'),
            new HostFunction('min', static fn(Context $c, mixed ...$values): mixed => self::extreme($values, -1), 1, null, 'min(a, b, …) or min(list): the least'),
            new HostFunction('max', static fn(Context $c, mixed ...$values): mixed => self::extreme($values, 1), 1, null, 'max(a, b, …) or max(list): the greatest'),
            new HostFunction('str', static fn(Context $c, mixed $value): string => Ops::text($value), 1, 1, 'str(value): the value as text'),
            new HostFunction('num', static function (Context $c, mixed $value): int|float {
                if (\is_int($value) || \is_float($value)) {
                    return $value;
                }
                $text = Ops::text($value);
                if (!is_numeric($text)) {
                    throw new ScriptError(\sprintf('"%s" is not a number', $text));
                }

                return str_contains($text, '.') ? (float) $text : (int) $text;
            }, 1, 1, 'num(value): the value as a number'),
            new HostFunction('json.parse', static function (Context $c, mixed $text): mixed {
                try {
                    return json_decode(Ops::text($text), true, 64, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new ScriptError('Not JSON: ' . $e->getMessage());
                }
            }, 1, 1, 'json.parse(text): the value the JSON text holds'),
            new HostFunction('settings.get', static function (Context $c, mixed $key): string|bool|null {
                return $c->grant->settings[Ops::text($key)] ?? null;
            }, 1, 1, 'settings.get(key): the value of one of the extension\'s settings, as the manager saved it; null when none'),
            new HostFunction('json.text', static fn(Context $c, mixed $value): string => (string) json_encode(Ops::plain($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 1, 1, 'json.text(value): the value as JSON'),
        ];
    }

    private static function errorValue(string $kind): \Closure
    {
        return static fn(Context $c, mixed $message, mixed $data = null): array => ['kind' => $kind, 'message' => Ops::text($message), 'data' => $data];
    }

    /**
     * @param array<mixed> $values
     */
    private static function extreme(array $values, int $sign): mixed
    {
        if (\count($values) === 1 && \is_array($values[0])) {
            $values = array_values($values[0]);
        }
        if ($values === []) {
            return null;
        }
        $extreme = $values[0];
        foreach ($values as $value) {
            if (Ops::cmp($value, $extreme) * $sign > 0) {
                $extreme = $value;
            }
        }

        return $extreme;
    }
}
