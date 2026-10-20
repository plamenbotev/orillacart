<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Cart\Application\AbandonedCarts;
use OrillaCart\Cart\Application\CartLinks;
use OrillaCart\Cart\Application\Dto\CartLineView;
use OrillaCart\Cart\Application\Dto\CartView;
use OrillaCart\Coupons\Application\CouponService;
use OrillaCart\Jobs\Application\Jobs;
use OrillaCart\Notifications\Application\Dto\Email;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailSettingsRepository;
use OrillaCart\Settings\Application\SettingsService;
use Psr\Clock\ClockInterface;

/**
 * Reminds customers who asked at checkout of the cart they left there: once, the shop's hours after they left it,
 * by a job in the mail lane, with a link that brings the cart back and the coupon the shop offers, if any.
 */
final readonly class CartReminders
{
    public function __construct(
        private AbandonedCarts $abandoned,
        private CartLinks $links,
        private CouponService $coupons,
        private EmailSettingsRepository $settings,
        private SettingsService $shop,
        private EmailWriter $writer,
        private Mailer $mailer,
        private Jobs $jobs,
        private ClockInterface $clock,
    ) {}

    /**
     * Counts the carts left for the shop's hours abandoned, and queues the reminders of those who asked.
     *
     * @return int how many reminders were queued
     */
    public function run(): int
    {
        $settings = $this->settings->load();
        $remind = $this->abandoned->mark($this->clock->now()->sub(new \DateInterval("PT{$settings->cartReminderHours}H")));
        if (!$settings->sends(EmailKind::CartReminder)) {
            return 0;
        }
        foreach ($remind as $id) {
            $this->jobs->push(SendCartReminder::TYPE, ['abandoned' => $id], EmailKind::CartReminder->value . ":{$id}");
        }

        return \count($remind);
    }

    /**
     * Writes the reminder of a cart left at checkout, unless there is nothing to remind of any more.
     *
     * @throws EmailNotSent the mail system refused it
     * @throws EmailNotWritten
     */
    public function send(int $abandonedId): void
    {
        $settings = $this->settings->load();
        if (!$settings->sends(EmailKind::CartReminder)) {
            return;
        }
        $reminder = $this->abandoned->reminder($abandonedId);
        if ($reminder === null) {
            return;
        }
        $coupon = $settings->cartReminderCoupon === null ? null : $this->coupons->offerable($settings->cartReminderCoupon, $this->clock->now());
        $email = $this->writer->write(EmailKind::CartReminder->template(), EmailKind::CartReminder->value, $reminder->email, $reminder->locale, [
            'cart' => self::cart($reminder->cart, $this->shop->current()->currency),
            'cart_url' => $this->links->reminderUrl($reminder->recoverToken, $coupon?->code),
            'coupon' => $coupon === null ? null : ['code' => $coupon->code, 'description' => $coupon->description],
        ]);
        if (!$this->mailer->send($email)) {
            throw new EmailNotSent('The cart reminder was not sent: the mail system refused it.');
        }
        $this->abandoned->reminded($abandonedId);
    }

    /**
     * The reminder with a cart of two items, for the template editor.
     *
     * @throws EmailNotWritten
     */
    public function preview(string $to): Email
    {
        $currency = $this->shop->current()->currency;
        $coupon = $this->settings->load()->cartReminderCoupon;

        return $this->writer->write(EmailKind::CartReminder->template(), EmailKind::CartReminder->value, $to, null, [
            'cart' => [
                'lines' => [
                    ['name' => 'Desk lamp', 'options' => [['attribute' => 'Colour', 'option' => 'Green']], 'quantity' => 1, 'total' => '40.00'],
                    ['name' => 'Light bulb', 'options' => [], 'quantity' => 2, 'total' => '9.00'],
                ],
                'subtotal' => '49.00',
                'currency' => $currency,
            ],
            'cart_url' => $this->links->reminderUrl(str_repeat('0', 64), $coupon),
            'coupon' => $coupon === null ? null : ['code' => $coupon, 'description' => ''],
        ]);
    }

    /**
     * @return array{lines: list<array{name: string, options: list<array{attribute: string, option: string}>, quantity: int, total: string}>, subtotal: string, currency: string}
     */
    private static function cart(CartView $cart, string $currency): array
    {
        $lines = array_values(array_filter($cart->lines, static fn(CartLineView $line): bool => $line->problem !== CartLineView::UNAVAILABLE));

        return [
            'lines' => array_map(static fn(CartLineView $line): array => ['name' => $line->name, 'options' => $line->options, 'quantity' => $line->quantity, 'total' => $line->total], $lines),
            'subtotal' => $cart->subtotal,
            'currency' => $currency,
        ];
    }
}
