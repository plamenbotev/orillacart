<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application;

use OrillaCart\Notifications\Application\Dto\Email;
use OrillaCart\Notifications\Application\Dto\SendOrderEmail;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Notifications\Domain\EmailRules;
use OrillaCart\Notifications\Domain\EmailSettingsRepository;
use OrillaCart\Ordering\Application\OrderLinks;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shipping\Application\ShipmentTracking;
use Psr\Log\LoggerInterface;

/**
 * Sends the emails about orders. A mail that cannot be sent is logged: it never stops the order.
 *
 * A template renders the body and has a "subject" block; a theme overrides it by placing a file of the same
 * name in its orillacart/emails/ folder.
 */
final readonly class OrderEmails
{
    public function __construct(
        private OrderService $orders,
        private OrderLinks $links,
        private EmailSettingsRepository $settings,
        private TemplateRenderer $templates,
        private Mailer $mailer,
        private Site $site,
        private LoggerInterface $logger,
        private ShipmentTracking $shipments,
    ) {}

    public function statusChanged(int $orderId, string $previous, string $status, bool $tellCustomer = true, bool $tellShop = true): void
    {
        foreach (EmailRules::forStatusChange($previous, $status) as $kind) {
            if ($kind->isForShop() ? $tellShop : $tellCustomer) {
                $this->send($orderId, $kind);
            }
        }
    }

    public function noteAdded(int $orderId, string $text): void
    {
        $this->send($orderId, EmailKind::CustomerNote, ['note' => $text]);
    }

    /**
     * Sends an order's email again; the shop asks from the order's screen.
     *
     * @throws OrderNotFound
     *
     * @return bool whether the mail system accepted it
     */
    public function resend(int $orderId, SendOrderEmail $command): bool
    {
        $this->orders->order($orderId);

        return $this->send($orderId, EmailKind::from($command->kind), ['note' => ''], force: true);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function send(int $orderId, EmailKind $kind, array $extra = [], bool $force = false): bool
    {
        $settings = $this->settings->load();
        if (!$force && !$settings->sends($kind)) {
            return false;
        }
        $order = $this->orders->order($orderId);
        $context = [
            'order' => $order,
            'receipt_url' => $this->links->receiptUrl($order->key),
            'site_name' => $this->site->name(),
            'site_url' => $this->site->url(),
            'for_shop' => $kind->isForShop(),
            'shipments' => $this->shipments->forOrder($orderId),
        ] + $extra;

        try {
            $email = new Email(
                $kind->isForShop() ? ($settings->shopAddress ?? $this->site->adminEmail()) : $order->email,
                trim($this->templates->renderBlock($kind->template(), 'subject', $context)),
                $this->templates->render($kind->template(), $context),
                $settings->fromName ?? $this->site->name(),
                $settings->fromAddress ?? $this->site->adminEmail(),
                $kind->isForShop() ? $order->email : null,
            );
        } catch (\Throwable $e) {
            $this->logger->error('The {kind} email of order {order} could not be written: {message}', ['kind' => $kind->value, 'order' => $orderId, 'message' => $e->getMessage(), 'exception' => $e]);

            return false;
        }

        $sent = $this->mailer->send($email);
        if (!$sent) {
            $this->logger->warning('The {kind} email of order {order} to {to} was not sent: the mail system refused it.', ['kind' => $kind->value, 'order' => $orderId, 'to' => $email->to]);
        }

        return $sent;
    }
}
