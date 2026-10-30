<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\WordPress;

use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailSettings;
use OrillaCart\Notifications\Domain\EmailSettingsRepository;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;
use OrillaCart\Shared\WordPress\AutoloadedOption;
use Psr\Log\LoggerInterface;

/**
 * Keeps the email settings in one WordPress option.
 */
final readonly class OptionEmailSettingsRepository implements EmailSettingsRepository, LifecycleListener, RemovesShopData
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
                \is_int($stored['reminder_days'] ?? null) ? $stored['reminder_days'] : EmailSettings::DEFAULT_REMINDER_DAYS,
                \is_int($stored['cart_reminder_hours'] ?? null) ? $stored['cart_reminder_hours'] : EmailSettings::DEFAULT_CART_REMINDER_HOURS,
                $text('cart_reminder_coupon'),
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
            'reminder_days' => $settings->reminderDays,
            'cart_reminder_hours' => $settings->cartReminderHours,
            'cart_reminder_coupon' => $settings->cartReminderCoupon,
        ], true);
    }

    public function activate(): void
    {
        $this->upgrade(null);
    }

    /** Read on every storefront request: stored with its defaults, autoloaded, so reading it costs no query. */
    public function upgrade(?string $fromVersion): void
    {
        AutoloadedOption::keep(self::OPTION, fn() => $this->save($this->load()));
    }

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }
}
