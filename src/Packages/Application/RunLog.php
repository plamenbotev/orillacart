<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Packages\Domain\ExtensionRun;

/**
 * The log of an extension's runs: every handler call and console run, with how it ended and the budget it used.
 * Bounded twice: the shop's history retention lets old runs go, and each extension keeps its latest few hundred.
 */
interface RunLog
{
    public const int KEPT = 500;

    /**
     * @param array<string, int|float> $used
     */
    public function record(string $extension, string $what, string $outcome, ?string $message, array $used, \DateTimeImmutable $at): void;

    /** @return list<ExtensionRun> newest first */
    public function recent(string $extension, int $limit = 50): array;

    public function forgetBefore(\DateTimeImmutable $before): void;

    public function forgetExtension(string $extension): void;
}
