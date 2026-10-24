<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Domain;

use OrillaCart\Shared\Domain\RuleViolation;

/**
 * Who the shop's emails come from, where the shop's own emails go, which emails are turned off, how many days
 * after an order was placed the customer is reminded to pay for it, and how many hours after a cart was left at
 * checkout its customer is reminded of it, with which coupon. Without a name or address of its own, the shop
 * uses the site's name and administration email.
 */
final readonly class EmailSettings
{
    public const int DEFAULT_REMINDER_DAYS = 3;
    public const int MAX_REMINDER_DAYS = 60;
    public const int DEFAULT_CART_REMINDER_HOURS = 4;
    public const int MAX_CART_REMINDER_HOURS = 168;

    /**
     * @param list<EmailKind> $disabled
     */
    public function __construct(
        public ?string $fromName = null,
        public ?string $fromAddress = null,
        public ?string $shopAddress = null,
        public array $disabled = [],
        public int $reminderDays = self::DEFAULT_REMINDER_DAYS,
        /** Hours after the customer left the cart at checkout: then it counts abandoned, and they are reminded. */
        public int $cartReminderHours = self::DEFAULT_CART_REMINDER_HOURS,
        /** The code of a coupon the cart reminder offers; none for none. */
        public ?string $cartReminderCoupon = null,
    ) {
        if ($cartReminderHours < 1 || $cartReminderHours > self::MAX_CART_REMINDER_HOURS) {
            throw new RuleViolation('The cart reminder goes 1 to %d hours after the cart was left.', 'orillacart_invalid_cart_reminder_hours', values: [self::MAX_CART_REMINDER_HOURS]);
        }
        if ($reminderDays < 1 || $reminderDays > self::MAX_REMINDER_DAYS) {
            throw new RuleViolation('The payment reminder goes 1 to %d days after the order.', 'orillacart_invalid_reminder_days', values: [self::MAX_REMINDER_DAYS]);
        }
        foreach (['sender' => $fromAddress, 'shop' => $shopAddress] as $which => $address) {
            if ($address !== null && filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuleViolation('The %1$s address "%2$s" is not an email address.', 'orillacart_invalid_email', values: [$which, $address]);
            }
        }
        if ($fromName !== null && (trim($fromName) === '' || mb_strlen($fromName) > 100 || preg_match('/[\r\n<>"]/', $fromName) === 1)) {
            throw new RuleViolation('The sender name has up to 100 characters, without line breaks, quotes or angle brackets.', 'orillacart_invalid_sender_name');
        }
    }

    public static function defaults(): self
    {
        return new self();
    }

    public function sends(EmailKind $kind): bool
    {
        return !\in_array($kind, $this->disabled, true);
    }
}
