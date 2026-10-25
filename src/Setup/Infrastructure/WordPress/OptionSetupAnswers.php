<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Infrastructure\WordPress;

use OrillaCart\Setup\Application\SetupAnswersRepository;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;

/**
 * The setup's answers in an option of their own, and the settings file from before the setup last applied them.
 */
final readonly class OptionSetupAnswers implements SetupAnswersRepository, LifecycleListener, RemovesShopData
{
    public const string OPTION = 'orillacart_setup_answers';
    public const string BEFORE_OPTION = 'orillacart_setup_before';

    public function load(): array
    {
        $stored = get_option(self::OPTION);
        if (!\is_array($stored)) {
            return [];
        }
        $answers = [];
        foreach ($stored as $step => $section) {
            if (\is_string($step) && \is_array($section)) {
                $answers[$step] = $section;
            }
        }

        return $answers;
    }

    public function save(array $answers): void
    {
        update_option(self::OPTION, $answers, false);
    }

    public function keepBefore(array $settings): void
    {
        update_option(self::BEFORE_OPTION, $settings, false);
    }

    public function before(): ?array
    {
        $before = get_option(self::BEFORE_OPTION);

        return \is_array($before) ? $before : null;
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
        delete_option(self::BEFORE_OPTION);
    }
}
