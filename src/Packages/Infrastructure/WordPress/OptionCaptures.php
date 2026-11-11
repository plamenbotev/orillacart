<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Infrastructure\WordPress;

use OrillaCart\Packages\Application\Captures;

/**
 * The live captures in one option, read with the rest of the site's options: a handler of an extension with no
 * capture armed costs nothing. Two requests at once may both take the last run, which records one more.
 */
final readonly class OptionCaptures implements Captures
{
    public const string OPTION = 'orillacart_extension_captures';

    public function arm(string $extension, ?string $what, int $runs, \DateTimeImmutable $until): void
    {
        $all = self::all();
        $all[$extension] = ['what' => $what === null || trim($what) === '' ? null : trim($what), 'left' => max(1, min(self::MOST_RUNS, $runs)), 'until' => $until->getTimestamp()];
        update_option(self::OPTION, $all, true);
    }

    public function take(string $extension, string $what, \DateTimeImmutable $now): bool
    {
        $all = self::all();
        $capture = $all[$extension] ?? null;
        if ($capture === null) {
            return false;
        }
        if ($capture['until'] < $now->getTimestamp() || $capture['left'] < 1) {
            unset($all[$extension]);
            update_option(self::OPTION, $all, true);

            return false;
        }
        if ($capture['what'] !== null && !str_starts_with($what, $capture['what'])) {
            return false;
        }
        --$all[$extension]['left'];
        if ($all[$extension]['left'] < 1) {
            unset($all[$extension]);
        }
        update_option(self::OPTION, $all, true);

        return true;
    }

    public function status(string $extension, \DateTimeImmutable $now): ?array
    {
        $capture = self::all()[$extension] ?? null;
        if ($capture === null || $capture['until'] < $now->getTimestamp() || $capture['left'] < 1) {
            return null;
        }

        return ['what' => $capture['what'], 'left' => $capture['left'], 'until' => $now->setTimestamp($capture['until'])->format(\DATE_ATOM)];
    }

    public function stop(string $extension): void
    {
        $all = self::all();
        unset($all[$extension]);
        update_option(self::OPTION, $all, true);
    }

    /** @return array<string, array{what: ?string, left: int, until: int}> */
    private static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        $all = [];
        foreach (\is_array($stored) ? $stored : [] as $extension => $capture) {
            if (\is_string($extension) && \is_array($capture) && \is_int($capture['left'] ?? null) && \is_int($capture['until'] ?? null)) {
                $all[$extension] = ['what' => \is_string($capture['what'] ?? null) ? $capture['what'] : null, 'left' => $capture['left'], 'until' => $capture['until']];
            }
        }

        return $all;
    }
}
