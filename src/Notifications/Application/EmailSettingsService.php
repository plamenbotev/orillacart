<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Notifications\Application\Dto\EmailSettingsView;
use OrillaCart\Notifications\Application\Dto\UpdateEmailSettings;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailSettings;
use OrillaCart\Notifications\Domain\EmailSettingsRepository;

final readonly class EmailSettingsService
{
    public function __construct(
        private EmailSettingsRepository $settings,
        private Site $site,
    ) {}

    public function current(): EmailSettingsView
    {
        return $this->view($this->settings->load());
    }

    public function update(UpdateEmailSettings $command): EmailSettingsView
    {
        $blank = static fn(?string $value): ?string => $value === null || trim($value) === '' ? null : trim($value);
        $settings = new EmailSettings(
            $blank($command->fromName),
            $blank($command->fromAddress),
            $blank($command->shopAddress),
            array_values(array_unique(array_map(EmailKind::from(...), $command->disabled), SORT_REGULAR)),
            $command->reminderDays,
            $command->cartReminderHours,
            $blank($command->cartReminderCoupon),
        );
        $this->settings->save($settings);

        return $this->view($settings);
    }

    private function view(EmailSettings $settings): EmailSettingsView
    {
        return new EmailSettingsView(
            $settings->fromName,
            $settings->fromAddress,
            $settings->shopAddress,
            array_map(static fn(EmailKind $kind): string => $kind->value, $settings->disabled),
            $this->site->name(),
            $this->site->adminEmail(),
            EmailKind::values(),
            $settings->reminderDays,
            $settings->cartReminderHours,
            $settings->cartReminderCoupon,
        );
    }

    /** Who emails come from, and where the shop's own go; the other email settings as they are. */
    public function sender(?string $fromName, ?string $fromAddress, ?string $shopAddress): EmailSettingsView
    {
        $current = $this->current();

        return $this->update(new UpdateEmailSettings($fromName, $fromAddress, $shopAddress, $current->disabled, $current->reminderDays, $current->cartReminderHours, $current->cartReminderCoupon));
    }
}
