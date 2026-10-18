<?php

declare(strict_types=1);

namespace OrillaCart\Geography\Domain;

/**
 * How an address is written in its country: lines of {name}, {first_name}, {last_name}, {street}, {city},
 * {postcode}, {state} (its name), {state_code} and {country} (its name). Empty parts drop out, and a line left
 * empty goes.
 */
final class AddressFormat
{
    public const string DEFAULT = "{name}\n{street}\n{postcode} {city}\n{state}\n{country}";

    /** Countries that write addresses otherwise, after the conventions their post offices publish. */
    private const array BY_COUNTRY = [
        'AU' => "{name}\n{street}\n{city} {state_code} {postcode}\n{country}",
        'CA' => "{name}\n{street}\n{city} {state_code} {postcode}\n{country}",
        'CN' => "{country} {postcode}\n{state}, {city}, {street}\n{name}",
        'GB' => "{name}\n{street}\n{city}\n{state}\n{postcode}\n{country}",
        'HK' => "{name}\n{street}\n{city}\n{state}\n{country}",
        'IE' => "{name}\n{street}\n{city}\n{state}\n{postcode}\n{country}",
        'IN' => "{name}\n{street}\n{city} {postcode}\n{state}\n{country}",
        'IT' => "{name}\n{street}\n{postcode} {city} {state_code}\n{country}",
        'JP' => "{postcode}\n{state} {city} {street}\n{last_name} {first_name}\n{country}",
        'NZ' => "{name}\n{street}\n{city} {postcode}\n{country}",
        'US' => "{name}\n{street}\n{city}, {state_code} {postcode}\n{country}",
    ];

    public static function of(string $country): string
    {
        return self::BY_COUNTRY[strtoupper($country)] ?? self::DEFAULT;
    }

    /**
     * @param array<string, string> $parts by token name, without braces
     *
     * @return list<string>
     */
    public static function lines(string $format, array $parts): array
    {
        $replace = [];
        foreach ($parts as $token => $value) {
            $replace['{' . $token . '}'] = $value;
        }
        $lines = [];
        foreach (explode("\n", $format) as $line) {
            $line = (string) preg_replace('/\{[a-z_]+\}/', '', strtr($line, $replace));
            // Separators around parts that were empty: "Sofia, , 1000" or ", Sofia".
            $line = trim((string) preg_replace(['/\s+/u', '/(\s*,)+/u'], [' ', ','], $line), " ,\t");
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
