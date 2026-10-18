<?php

declare(strict_types=1);

namespace OrillaCart\Search\Infrastructure\WordPress;

use OrillaCart\Search\Application\WorkerSignals;
use OrillaCart\Shared\Lifecycle\LifecycleListener;

/**
 * Workers' signals in two options, read straight from the database: WordPress caches options for the life of a
 * process, and a worker lives for hours.
 */
final readonly class OptionWorkerSignals implements WorkerSignals, LifecycleListener
{
    public const string RESTART = 'orillacart_index_restart';
    public const string WORKERS = 'orillacart_index_workers';

    public function restartRequestedAt(): ?\DateTimeImmutable
    {
        $at = self::read(self::RESTART);

        return is_numeric($at) ? new \DateTimeImmutable('@' . (int) $at) : null;
    }

    public function requestRestart(\DateTimeImmutable $at): void
    {
        update_option(self::RESTART, (string) $at->getTimestamp(), false);
    }

    public function seen(string $worker, \DateTimeImmutable $at, \DateTimeImmutable $forgetBefore): void
    {
        $workers = [];
        foreach ($this->workers() as $name => $seen) {
            if ($seen >= $forgetBefore) {
                $workers[$name] = $seen->getTimestamp();
            }
        }
        $workers[$worker] = $at->getTimestamp();
        update_option(self::WORKERS, (string) wp_json_encode($workers), false);
    }

    public function workers(): array
    {
        $stored = json_decode((string) self::read(self::WORKERS), true);
        $workers = [];
        foreach (\is_array($stored) ? $stored : [] as $name => $seen) {
            if (\is_int($seen)) {
                $workers[(string) $name] = new \DateTimeImmutable('@' . $seen);
            }
        }

        return $workers;
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::RESTART);
        delete_option(self::WORKERS);
    }

    private static function read(string $option): ?string
    {
        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            return null;
        }
        $value = $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $option));

        return \is_string($value) ? $value : null;
    }
}
