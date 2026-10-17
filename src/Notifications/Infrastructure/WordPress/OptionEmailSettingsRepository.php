<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\WordPress;

use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailSettings;
use OrillaCart\Notifications\Domain\EmailSettingsRepository;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use Psr\Log\LoggerInterface;

/**
 * Keeps the email settings in one WordPress option.
 */
final readonly class OptionEmailSettingsRepository implements EmailSettingsRepository, LifecycleListener
{
    public const string OPTION = 'orillacart_email_settings';

    public function __construct(private LoggerInterface $logger) {}

    public function load(): EmailSettings
    {
        $stored = get_option(self::OPTION);
        if (!\is_array($stored)) {
            return EmailSettings::defaults();
        }
        $text = static fn(string $key): ?string => \is_string($stored[$key] ?? null) ? $stored[$key] : null;
        $disabled = \is_array($stored['disabled'] ?? null) ? $stored['disabled'] : [];

        try {
            return new EmailSettings(
                $text('from_name'),
                $text('from_address'),
                $text('shop_address'),
                array_values(array_filter(array_map(static fn(mixed $kind): ?EmailKind => \is_string($kind) ? EmailKind::tryFrom($kind) : null, $disabled))),
            );
        } catch (RuleViolation) {
            $this->logger->warning('Stored email settings are not valid, using the defaults.');

            return EmailSettings::defaults();
        }
    }

    public function save(EmailSettings $settings): void
    {
        update_option(self::OPTION, [
            'from_name' => $settings->fromName,
            'from_address' => $settings->fromAddress,
            'shop_address' => $settings->shopAddress,
            'disabled' => array_map(static fn(EmailKind $kind): string => $kind->value, $settings->disabled),
        ], false);
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }
}
