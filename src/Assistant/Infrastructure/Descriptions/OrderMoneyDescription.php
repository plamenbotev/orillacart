<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\Descriptions;

use OrillaCart\Assistant\Application\CallDescription;
use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;
use OrillaCart\Catalog\Domain\ProductContent;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Payment\Application\Dto\RefundView;
use OrillaCart\Payment\Application\Refunds;
use OrillaCart\Shared\Domain\NotFound;

/**
 * Money an order moves, as the owner weighs it: a refund with its amount, the order and customer, how the money
 * goes back and what else it does; a refund tried again; an order's status changed; an order the shop places; an
 * order marked paid; an order's customer forgotten.
 */
final readonly class OrderMoneyDescription implements CallDescription
{
    private const array STATUSES = [
        'pending_payment' => 'waiting for payment', 'on_hold' => 'on hold', 'processing' => 'processing',
        'shipped' => 'shipped', 'completed' => 'completed', 'cancelled' => 'cancelled', 'failed' => 'failed',
        'refunded' => 'refunded',
    ];

    public function __construct(
        private OrderService $orders,
        private Refunds $refunds,
        private ProductContent $products,
    ) {}

    public function describe(ToolDefinition $tool, array $arguments, Caller $caller): ?string
    {
        try {
            return match ($tool->name) {
                'refund_order' => $this->refund($arguments),
                'retry_refund' => $this->retry($arguments),
                'change_order_status' => $this->status($arguments),
                'create_order' => $this->placed($arguments),
                'mark_order_paid' => $this->paid($arguments),
                'anonymise_order' => $this->anonymised($arguments),
                default => null,
            };
        } catch (NotFound) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function refund(array $arguments): ?string
    {
        $id = Words::int($arguments, 'id');
        if ($id === null) {
            return null;
        }
        $order = $this->orders->order($id);
        $refunds = $this->refunds->forOrder($id);
        $amount = Words::string($arguments, 'amount');
        $lines = ["Gives back {$amount} {$order->currency} of " . Words::order($order) . '.', "The order came to {$order->total} {$order->currency}; {$order->refundedTotal} is refunded already, {$refunds->refundable} can be."];
        $lines[] = match (true) {
            Words::yes($arguments, 'store_credit') => $order->customerId === null ? 'As a gift card for the customer, not to their payment.' : 'As store credit on the customer\'s account, not to their payment.',
            Words::yes($arguments, 'outside') => 'Recorded as given back by the shop itself (a bank transfer, cash): no provider is asked.',
            $refunds->provider !== null => "Through {$refunds->provider}, to the customer's payment.",
            default => 'Recorded only: the payment has no provider that can give money back.',
        };
        $items = self::items($order, \is_array($arguments['lines'] ?? null) ? $arguments['lines'] : []);
        if ($items !== []) {
            $lines[] = 'For ' . implode(', ', $items) . (Words::yes($arguments, 'shipping') ? ', and the shipping.' : '.');
        } elseif (Words::yes($arguments, 'shipping')) {
            $lines[] = 'For the shipping.';
        }
        if (Words::yes($arguments, 'restock') && $items !== []) {
            $lines[] = 'The items go back in stock.';
        }
        if (Words::string($arguments, 'reason') !== '') {
            $lines[] = 'Reason: ' . Words::quoted(Words::string($arguments, 'reason'));
        }
        $lines[] = 'The customer is emailed about it.';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function retry(array $arguments): ?string
    {
        $id = Words::int($arguments, 'id');
        $orderId = $id === null ? null : $this->refunds->orderOf($id);
        if ($orderId === null) {
            return null;
        }
        $order = $this->orders->order($orderId);
        $refunds = $this->refunds->forOrder($orderId);
        $refund = array_values(array_filter($refunds->refunds, static fn(RefundView $refund): bool => $refund->id === $id))[0] ?? null;
        if ($refund === null) {
            return null;
        }

        return "Asks {$refund->method} again to give back {$refund->amount} {$refund->currency} of " . Words::order($order) . ', a refund it did not answer. If it went through the first time, asking again does not give it twice: the provider knows it by the same id.';
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function status(array $arguments): ?string
    {
        $id = Words::int($arguments, 'id');
        if ($id === null) {
            return null;
        }
        $order = $this->orders->order($id);
        $to = Words::string($arguments, 'status');
        $lines = ['Moves ' . Words::order($order) . ' from ' . (self::STATUSES[$order->status] ?? $order->status) . ' to ' . (self::STATUSES[$to] ?? $to) . ", {$order->total} {$order->currency}."];
        $lines[] = match ($to) {
            'cancelled', 'failed' => 'Its items go back in stock; no money is given back by this (refund_order does that).',
            'refunded' => 'It is marked refunded; no money is given back by this (refund_order does that).',
            'processing', 'completed', 'shipped' => $order->paidAt === null ? 'It is not paid yet: this treats it as paid.' : 'It is paid.',
            default => '',
        };
        $lines[] = 'The customer gets the email of the new status, where the shop sends one.';
        if (Words::string($arguments, 'note') !== '') {
            $lines[] = 'Note: ' . Words::quoted(Words::string($arguments, 'note'));
        }

        return implode("\n", array_filter($lines, static fn(string $line): bool => $line !== ''));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function placed(array $arguments): string
    {
        $items = [];
        foreach (\is_array($arguments['items'] ?? null) ? $arguments['items'] : [] as $item) {
            if (\is_array($item) && \is_int($item['product_id'] ?? null)) {
                $quantity = \is_int($item['quantity'] ?? null) ? $item['quantity'] : 1;
                $items[] = "{$quantity} × " . $this->products->name($item['product_id']);
            }
        }
        $lines = ['Places an order for ' . Words::string($arguments, 'email') . ': ' . ($items === [] ? 'no items' : implode(', ', $items)) . '.'];
        $lines[] = Words::yes($arguments, 'paid') ? 'It is recorded as paid already.' : 'It waits for the customer to pay.';
        $lines[] = Words::yes($arguments, 'email_customer') ? 'The customer is emailed about it.' : 'The customer is not emailed.';
        $lines[] = 'The items are taken from stock.';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function paid(array $arguments): ?string
    {
        $id = Words::int($arguments, 'id');
        if ($id === null) {
            return null;
        }
        $order = $this->orders->order($id);
        $lines = ['Records ' . Words::order($order) . " as paid: {$order->total} {$order->currency} came to the shop outside checkout, as cash or a bank transfer. No money is taken by this."];
        $lines[] = $order->status === 'on_hold' || $order->status === 'pending_payment' ? 'The order then moves on to be fulfilled, and the customer gets that status\'s email.' : 'Its status stays ' . (self::STATUSES[$order->status] ?? $order->status) . '.';
        if (Words::string($arguments, 'note') !== '') {
            $lines[] = 'Note: ' . Words::quoted(Words::string($arguments, 'note'));
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function anonymised(array $arguments): ?string
    {
        $id = Words::int($arguments, 'id');
        if ($id === null) {
            return null;
        }

        return 'Forgets the customer of ' . Words::order($this->orders->order($id)) . ' for good: their name, email, addresses and answers are wiped from it, and cannot come back. Its sums stay for the books.';
    }

    /**
     * @param array<mixed> $refunded
     *
     * @return list<string> "2 × Blue shirt"
     */
    private static function items(OrderView $order, array $refunded): array
    {
        $items = [];
        foreach ($refunded as $line) {
            if (\is_array($line) && \is_int($line['line'] ?? null) && isset($order->lines[$line['line']])) {
                $quantity = \is_int($line['quantity'] ?? null) ? $line['quantity'] : 1;
                $items[] = "{$quantity} × {$order->lines[$line['line']]->name}";
            }
        }

        return $items;
    }
}
