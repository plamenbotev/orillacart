<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\Descriptions;

use OrillaCart\Assistant\Application\CallDescription;
use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;
use OrillaCart\Notifications\Application\OrderEmails;
use OrillaCart\Notifications\Domain\EmailKind;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Shared\Domain\NotFound;

/**
 * What reaches a customer, as the owner weighs it: which email goes to whom, a note they read, a shipment booked
 * with its courier and the tracking they are sent.
 */
final readonly class CustomerMessageDescription implements CallDescription
{
    public function __construct(
        private OrderService $orders,
        private OrderEmails $emails,
    ) {}

    public function describe(ToolDefinition $tool, array $arguments, Caller $caller): ?string
    {
        $id = Words::int($arguments, 'id');
        if ($id === null || !\in_array($tool->name, ['send_order_email', 'add_order_note', 'book_shipment'], true)) {
            return null;
        }
        try {
            $order = $this->orders->order($id);
            if ($tool->name === 'send_order_email') {
                $kind = EmailKind::tryFrom(Words::string($arguments, 'kind'));

                return $kind === null ? null : \sprintf('Sends the "%s" email of %s to %s%s.', str_replace('_', ' ', $kind->value), Words::order($order), $this->emails->recipient($id, $kind), $kind->isForShop() ? ' (the shop\'s own address)' : '');
            }
            if ($tool->name === 'add_order_note') {
                $text = Words::quoted(Words::string($arguments, 'text'), 1500);

                return Words::yes($arguments, 'for_customer')
                    ? 'Emails ' . ($order->anonymised ? 'nobody: the order is anonymous' : $order->email) . ' this note on ' . Words::order($order) . ":\n{$text}"
                    : 'Adds a note only the shop reads to ' . Words::order($order) . ":\n{$text}";
            }
            $items = [];
            foreach (\is_array($arguments['items'] ?? null) ? $arguments['items'] : [] as $item) {
                if (\is_array($item) && \is_int($item['line'] ?? null) && isset($order->lines[$item['line']])) {
                    $items[] = (\is_int($item['quantity'] ?? null) ? $item['quantity'] : 1) . ' × ' . $order->lines[$item['line']]->name;
                }
            }
            $parcels = Words::int($arguments, 'parcels') ?? 1;

            return 'Books a shipment of ' . Words::order($order) . ' with its courier (' . ($order->shipping ?? 'its shipping') . '), which may charge for it: '
                . ($items === [] ? 'everything not sent yet' : implode(', ', $items)) . ", in {$parcels} " . ($parcels === 1 ? 'parcel' : 'parcels') . '.'
                . "\nThe customer is emailed the tracking.";
        } catch (NotFound) {
            return null;
        }
    }
}
