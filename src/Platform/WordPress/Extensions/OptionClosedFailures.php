<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Extensions;

use OrillaCart\Shared\Extension\ClosedFailures;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use Psr\Clock\ClockInterface;

/**
 * The failures of add-ons the shop cannot do without, in an option of the site: the latest of each part, for a
 * day. Written at most once a minute for each, as a broken rule fails on every cart a shopper opens.
 */
final readonly class OptionClosedFailures implements ClosedFailures, LifecycleListener
{
    public const string OPTION = 'orillacart_closed_failures';
    private const int KEEP = 86400;
    private const int QUIET = 60;
    private const int MAX = 50;

    public function __construct(private ClockInterface $clock) {}

    public function failed(string $id, string $what): void
    {
        $now = $this->clock->now()->getTimestamp();
        $kept = self::kept(get_option(self::OPTION, []));
        if (isset($kept[$id]) && $now - $kept[$id]['at'] < self::QUIET && $kept[$id]['what'] === $what) {
            return;
        }
        $kept[$id] = ['what' => mb_substr($what, 0, 500), 'at' => $now];
        $kept = array_filter($kept, static fn(array $failure): bool => $now - $failure['at'] < self::KEEP);
        uasort($kept, static fn(array $a, array $b): int => $b['at'] <=> $a['at']);
        update_option(self::OPTION, \array_slice($kept, 0, self::MAX, true), false);
    }

    public function since(\DateTimeImmutable $since): array
    {
        $found = [];
        foreach (self::kept(get_option(self::OPTION, [])) as $id => $failure) {
            if ($failure['at'] >= $since->getTimestamp()) {
                $found[] = ['id' => (string) $id, 'what' => $failure['what'], 'at' => new \DateTimeImmutable('@' . $failure['at'])];
            }
        }
        usort($found, static fn(array $a, array $b): int => $b['at'] <=> $a['at']);

        return $found;
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    /** Only a record of what failed lately: it goes with the plugin, whatever the shop keeps. */
    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }

    /** @return array<string, array{what: string, at: int}> */
    private static function kept(mixed $stored): array
    {
        $kept = [];
        foreach (\is_array($stored) ? $stored : [] as $id => $failure) {
            if (\is_array($failure) && \is_string($failure['what'] ?? null) && \is_int($failure['at'] ?? null)) {
                $kept[(string) $id] = ['what' => $failure['what'], 'at' => $failure['at']];
            }
        }

        return $kept;
    }
}
