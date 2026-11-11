<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Packages\Domain\ExtensionRecording;

/**
 * The recordings an extension's runs left for its debugger (design 71): a test or console run debugged, or a
 * live call captured. They hold what the run saw, customers' data among it, so they are kept a day at most and
 * each extension its latest few.
 */
interface Recordings
{
    public const int KEPT = 20;

    public const int KEPT_HOURS = 24;

    public function save(ExtensionRecording $recording): void;

    /** @return list<ExtensionRecording> newest first */
    public function recent(string $extension): array;

    public function find(string $extension, int $id): ?ExtensionRecording;

    public function delete(ExtensionRecording $recording): void;

    public function forgetBefore(\DateTimeImmutable $before): void;

    public function forgetExtension(string $extension): void;
}
