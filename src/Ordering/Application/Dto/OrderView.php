<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Ordering\Domain\TaxLine;

/**
 * An order as its receipt shows it.
 */
final readonly class OrderView
{
    /**
     * @param list<OrderLineView> $lines
     * @param list<TaxLineView>   $taxes
     */
    public function __construct(
        public int $number,
        public string $key,
        public string $status,
        public string $email,
        public AddressData $billing,
        public ?AddressData $shippingAddress,
        public array $lines,
        public ?string $shipping,
        public string $shippingTotal,
        public string $shippingTax,
        public array $taxes,
        public string $subtotal,
        public string $itemsTax,
        public string $total,
        public string $currency,
        public ?string $paymentGateway,
        public string $paymentName,
        public string $paymentNote,
        public string $note,
        public string $placedAt,
        public ?string $paidAt,
    ) {}

    public static function fromOrder(Order $order): self
    {
        $shippingAddress = $order->shippingAddress();

        return new self(
            $order->id(),
            $order->key(),
            $order->status()->value,
            $order->email(),
            AddressData::fromAddress($order->billing()),
            $shippingAddress === null ? null : AddressData::fromAddress($shippingAddress),
            array_map(OrderLineView::fromLine(...), $order->lines()),
            $order->shippingName(),
            (string) $order->shippingTotal(),
            (string) $order->shippingTax(),
            array_map(static fn(TaxLine $tax): TaxLineView => new TaxLineView($tax->name, $tax->percent, (string) $tax->amount), $order->taxes()),
            (string) $order->subtotal(),
            (string) $order->itemsTax(),
            (string) $order->total(),
            $order->currency(),
            $order->paymentGateway(),
            $order->paymentName(),
            $order->paymentNote(),
            $order->note(),
            $order->placedAt()->format(\DATE_ATOM),
            $order->paidAt()?->format(\DATE_ATOM),
        );
    }
}
