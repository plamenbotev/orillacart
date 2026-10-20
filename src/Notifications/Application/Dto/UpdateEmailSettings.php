<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application\Dto;

use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailSettings;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: change who the shop's emails come from, where the shop's own go, which are turned off, when the
 * payment reminder goes, and when the cart reminder goes, with which coupon.
 * Empty name or addresses use the site's.
 */
final readonly class UpdateEmailSettings
{
    /**
     * @param list<string> $disabled
     */
    public function __construct(
        #[Assert\Length(max: 100)]
        public ?string $fromName = null,
        #[Assert\Email]
        public ?string $fromAddress = null,
        #[Assert\Email]
        public ?string $shopAddress = null,
        #[Assert\All([new Assert\Choice(callback: [EmailKind::class, 'values'])])]
        public array $disabled = [],
        #[Assert\Range(min: 1, max: EmailSettings::MAX_REMINDER_DAYS)]
        public int $reminderDays = EmailSettings::DEFAULT_REMINDER_DAYS,
        #[Assert\Range(min: 1, max: EmailSettings::MAX_CART_REMINDER_HOURS)]
        public int $cartReminderHours = EmailSettings::DEFAULT_CART_REMINDER_HOURS,
        #[Assert\Length(max: 100)]
        public ?string $cartReminderCoupon = null,
    ) {}
}
