<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Extension\Setting;
use OrillaCart\Packages\Application\Dto\PackageManifest;
use OrillaCart\Packages\Domain\Package;
use OrillaCart\Scripting\Application\Recorder;

/**
 * An extension's runs for its debugger (design 71): a recording as the debugger reads it, every line told by its
 * file, and an error's place and stack, `main.ocs:12 in price_for, from main.ocs:30 in on order_paid`, for every
 * run whether recorded or not.
 *
 * Lines are the compiled source's, the extension's `.ocs` files joined in order; each file's lines are told apart
 * as the check's findings are (PackageService::locate()).
 */
final class Debugging
{
    /**
     * A compiled line as the file and line it is in.
     *
     * @param array<string, string> $scripts path → content, in the order they were joined
     *
     * @return array{file: string, line: int}
     */
    public static function place(array $scripts, int $line): array
    {
        $file = '';
        $offset = 0;
        $start = 1;
        foreach ($scripts as $path => $content) {
            if ($line >= $start) {
                $file = $path;
                $offset = $start - 1;
            }
            $start += substr_count($content, "\n") + 1;
        }

        return ['file' => $file, 'line' => max(0, $line - $offset)];
    }

    /**
     * @param array<string, string>               $scripts
     * @param list<array{fn: string, line: int}> $stack innermost first
     *
     * @return list<array{fn: string, file: string, line: int}>
     */
    public static function stack(array $scripts, array $stack): array
    {
        return array_map(static fn(array $frame): array => ['fn' => $frame['fn']] + self::place($scripts, $frame['line']), $stack);
    }

    /**
     * Where an error happened, in words: `main.ocs:12 in price_for, from main.ocs:30 in on order_paid`.
     *
     * @param array<string, string>               $scripts
     * @param list<array{fn: string, line: int}> $stack
     */
    public static function where(array $scripts, array $stack): string
    {
        $parts = [];
        foreach (\array_slice(self::stack($scripts, $stack), 0, 5) as $index => $frame) {
            $parts[] = ($index === 0 ? '' : 'from ') . $frame['file'] . ':' . $frame['line'] . ' in ' . $frame['fn'];
        }

        return implode(', ', $parts);
    }

    /**
     * The message with where it happened, for the log of runs.
     *
     * @param array<string, string>               $scripts
     * @param list<array{fn: string, line: int}> $stack
     */
    public static function located(string $message, array $scripts, array $stack): string
    {
        $where = self::where($scripts, $stack);

        return $where === '' ? $message : $message . ' (at ' . $where . ')';
    }

    /**
     * The values a recording of the extension never shows: its secret settings'.
     *
     * @return list<string>
     */
    public static function secrets(Package $package, ?PackageManifest $manifest): array
    {
        $secrets = [];
        $values = $package->settings();
        foreach ($manifest?->settings() ?? [] as $setting) {
            $value = $values[$setting->key] ?? null;
            if ($setting->type === Setting::SECRET && \is_string($value) && \strlen($value) >= 4) {
                $secrets[] = $value;
            }
        }

        return $secrets;
    }

    /**
     * The recording as the debugger reads it: what ran, how it ended, the files it ran, its steps with each line
     * told by its file, and where it failed.
     *
     * @param array<string, string>               $scripts the `.ocs` files it ran, in order
     * @param list<array{fn: string, line: int}> $stack   where it failed, innermost first; empty when it did not
     *
     * @return array{what: string, outcome: string, message: ?string, cut: bool, sources: array<string, string>, steps: list<array<string, mixed>>, stack: list<array{fn: string, file: string, line: int}>}
     */
    public static function recording(Recorder $recorder, array $scripts, string $what, string $outcome, ?string $message, array $stack): array
    {
        $steps = [];
        foreach ($recorder->events() as $event) {
            if (isset($event['line']) && \is_int($event['line'])) {
                $event = self::place($scripts, $event['line']) + $event;
            }
            $steps[] = $event;
        }

        return [
            'what' => $what,
            'outcome' => $outcome,
            'message' => $message,
            'cut' => $recorder->cut(),
            'sources' => $scripts,
            'steps' => $steps,
            'stack' => self::stack($scripts, $stack),
        ];
    }
}
