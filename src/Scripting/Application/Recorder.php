<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application;

use OrillaCart\Shared\Domain\Money;

/**
 * Records one run of a script for its debugger (design 71): every line it reached with the variables there, the
 * functions it entered and what they returned, each host call with its arguments and answer, what `debug()`
 * marked, and where it failed. A recording is made only when asked for (a test or console run debugged, a live
 * capture) from the script's debug build; it never changes what the script does.
 *
 * Bounded: at most MAX_EVENTS events and MAX_BYTES of values, after which it says it was cut and records no more
 * while the run goes on. Values are shaped for reading: long texts cut, long lists and maps their first items,
 * deep ones their top levels. Secrets never reach it: the extension's secret settings' values and anything under
 * a key that names a password, a token or a key show as REDACTED.
 *
 * Lines are the compiled source's, the extension's files joined; the package layer tells them by file.
 */
final class Recorder
{
    public const int MAX_EVENTS = 10000;
    public const int MAX_BYTES = 4_000_000;
    public const int MAX_TEXT = 200;
    public const int MAX_ITEMS = 50;
    public const int MAX_DEPTH = 4;
    public const string REDACTED = '•••';

    /** Keys whose values are secrets wherever they appear. */
    private const string SECRET_KEY = '/pass(word)?|secret|token|api[_-]?key|authori[sz]ation|cookie|^key$/i';

    /** @var list<array<string, mixed>> */
    private array $events = [];
    private int $bytes = 0;
    private bool $cut = false;

    /**
     * @param list<string> $secrets values never shown: the extension's secret settings
     */
    public function __construct(private readonly array $secrets = []) {}

    /** @param array<string, mixed> $vars */
    public function line(int $line, int $depth, array $vars): void
    {
        $shaped = [];
        foreach ($vars as $name => $value) {
            $shaped[$name] = $this->shape($value, 0, $name);
        }
        $this->add(['k' => 'line', 'line' => $line, 'depth' => $depth, 'vars' => $shaped]);
    }

    public function enter(string $function, int $line, int $depth): void
    {
        $this->add(['k' => 'enter', 'fn' => $function, 'line' => $line, 'depth' => $depth]);
    }

    public function returned(mixed $value, int $depth): void
    {
        $this->add(['k' => 'return', 'value' => $this->shape($value, 0), 'depth' => $depth]);
    }

    /** A function left by an error rather than a return. */
    public function unwound(int $depth): void
    {
        $this->add(['k' => 'unwind', 'depth' => $depth]);
    }

    /**
     * @param list<mixed> $args
     */
    public function call(string $name, array $args, mixed $result, ?string $error, int $line, int $depth): void
    {
        $this->add(['k' => 'call', 'name' => $name, 'args' => array_map(fn(mixed $arg): mixed => $this->shape($arg, 0), $args), 'result' => $error === null ? $this->shape($result, 0) : null, 'error' => $error, 'line' => $line, 'depth' => $depth]);
    }

    public function noted(mixed $value, int $line, int $depth): void
    {
        $this->add(['k' => 'debug', 'value' => $this->shape($value, 0), 'line' => $line, 'depth' => $depth]);
    }

    public function failed(string $message, int $line, int $depth): void
    {
        // The end of a run is always kept, cut or not.
        $this->events[] = ['k' => 'error', 'message' => $message, 'line' => $line, 'depth' => $depth];
    }

    /** @return list<array<string, mixed>> */
    public function events(): array
    {
        return $this->events;
    }

    public function cut(): bool
    {
        return $this->cut;
    }

    /** @param array<string, mixed> $event */
    private function add(array $event): void
    {
        if ($this->cut) {
            return;
        }
        $size = \strlen((string) json_encode($event, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
        if (\count($this->events) >= self::MAX_EVENTS || $this->bytes + $size > self::MAX_BYTES) {
            $this->cut = true;

            return;
        }
        $this->bytes += $size;
        $this->events[] = $event;
    }

    /** A value as the debugger shows it: JSON-safe, bounded, secrets hidden. */
    public function shape(mixed $value, int $depth, int|string|null $key = null): mixed
    {
        if ($key !== null && \is_string($key) && preg_match(self::SECRET_KEY, $key) === 1 && $value !== null && $value !== '') {
            return self::REDACTED;
        }
        if ($value === null || \is_bool($value) || \is_int($value)) {
            return $value;
        }
        if (\is_float($value)) {
            return is_finite($value) ? $value : (string) $value;
        }
        if ($value instanceof Money) {
            return ['$money' => $value->toFixed()];
        }
        if ($value instanceof \Closure) {
            return ['$fn' => true];
        }
        if (\is_string($value)) {
            foreach ($this->secrets as $secret) {
                if ($secret !== '' && str_contains($value, $secret)) {
                    return self::REDACTED;
                }
            }
            if (!mb_check_encoding($value, 'UTF-8')) {
                return ['$bytes' => \strlen($value)];
            }

            return mb_strlen($value) > self::MAX_TEXT ? ['$text' => mb_substr($value, 0, self::MAX_TEXT), 'length' => mb_strlen($value)] : $value;
        }
        if (\is_array($value)) {
            $list = array_is_list($value);
            if ($depth >= self::MAX_DEPTH) {
                return ['$more' => \count($value), 'list' => $list];
            }
            $shaped = [];
            foreach (\array_slice($value, 0, self::MAX_ITEMS, true) as $itemKey => $item) {
                $shaped[$itemKey] = $this->shape($item, $depth + 1, $itemKey);
            }
            if (\count($value) > self::MAX_ITEMS) {
                return ['$items' => $list ? array_values($shaped) : $shaped, 'count' => \count($value), 'list' => $list];
            }

            // An empty or a keyed map stays a JSON object.
            return $list ? $shaped : (object) $shaped;
        }

        return ['$value' => get_debug_type($value)];
    }
}
