<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application;

/**
 * Keeps the setup's answers as the owner gives them, so the setup can be left and taken up again, and what the
 * settings were before the setup last changed them.
 */
interface SetupAnswersRepository
{
    /** @return array<string, array<array-key, mixed>> each step's answers, as given */
    public function load(): array;

    /** @param array<string, array<array-key, mixed>> $answers */
    public function save(array $answers): void;

    /** @param array<array-key, mixed> $settings the settings file from before the setup applied its plan */
    public function keepBefore(array $settings): void;

    /** @return array<array-key, mixed>|null */
    public function before(): ?array;
}
