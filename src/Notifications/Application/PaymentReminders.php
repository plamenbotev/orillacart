<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailSettingsRepository;
use OrillaCart\Notifications\Domain\SentEmail;
use OrillaCart\Notifications\Domain\SentEmails;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Payment\Application\PaymentOptions;
use Psr\Clock\ClockInterface;

/**
 * Reminds customers of orders still on hold for their payment, as by bank transfer, the shop's number of days
 * after they ordered: once, and only within two weeks of that day, so turning reminders on never writes to the
 * customers of old orders. Cash on delivery is paid when the order arrives, and is not reminded.
 */
final readonly class PaymentReminders
{
    /** How long after the reminder's day an order not reminded yet still is: longer than WP-Cron ever waits. */
    private const string WINDOW = 'P14D';

    public function __construct(
        private OrderService $orders,
        private PaymentOptions $payments,
        private EmailSettingsRepository $settings,
        private SentEmails $sent,
        private OrderEmails $emails,
        private ClockInterface $clock,
    ) {}

    /** @return int how many were reminded */
    public function remind(): int
    {
        $settings = $this->settings->load();
        if (!$settings->sends(EmailKind::PaymentReminder)) {
            return 0;
        }
        $now = $this->clock->now();
        $before = $now->sub(new \DateInterval("P{$settings->reminderDays}D"));
        $reminded = 0;
        foreach ($this->orders->unpaidOnHold($before->sub(new \DateInterval(self::WINDOW)), $before, $this->payments->paidOffline()) as $orderId) {
            $mark = EmailKind::PaymentReminder->value . ":{$orderId}";
            if ($this->sent->wasSent($mark)) {
                continue;
            }
            $this->emails->paymentReminder($orderId);
            $this->sent->remember(new SentEmail($mark, $now));
            ++$reminded;
        }

        return $reminded;
    }
}
