<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\DownloadGrant;
use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Ordering\Domain\OrderDiscount;
use OrillaCart\Ordering\Domain\OrderNote;
use OrillaCart\Ordering\Domain\OrderStatus;
use OrillaCart\Ordering\Domain\TaxLine;

/**
 * An order as its receipt shows it. The shop's view also has the notes only the shop sees, and the statuses
 * the order can move to.
 */
final readonly class OrderView
{
    /**
     * @param list<OrderLineView> $lines
     * @param list<TaxLineView>   $taxes
     * @param list<OrderNoteView> $notes
     * @param list<string>        $nextStatuses empty in the customer's view
     * @param list<OrderDownloadView> $downloads once the order is paid
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
        public ?int $customerId,
        public ?string $transactionId,
        public array $notes,
        public array $nextStatuses,
        public array $downloads = [],
        public ?string $shippingOption = null,
        public ?string $pickupPointId = null,
        /** The office or locker it goes to: its name and address. */
        public ?string $pickupPoint = null,
        /** What was given back, in all. */
        public string $refundedTotal = '0',
        public bool $shippingRefunded = false,
        /** What coupons took off the items; the subtotal is before it. */
        public string $discountTotal = '0',
        /** @var list<OrderDiscountView> the coupons it used */
        public array $discounts = [],
        /** @var list<OrderFieldView> answers to add-ons' checkout fields */
        public array $fields = [],
        /** Why the shop has to look at it, such as a disputed payment; for the shop only. */
        public ?string $attention = null,
        /** Unpaid, and waiting for the money or failed to pay: the customer can pay it now. */
        public bool $payable = false,
        /** The amounts include tax: its lines say how much of the total is tax, they do not add to it. */
        public bool $pricesIncludeTax = false,
        public ?string $vatNumber = null,
        /** Sold without VAT: the business customer accounts for it. */
        public bool $reverseCharge = false,
        /** The customer's details were erased, as a privacy request asked. */
        public bool $anonymised = false,
    ) {}

    /**
     * @param (\Closure(string): string)|null $downloadUrl the link of a download token; without it, no downloads are listed
     */
    public static function fromOrder(Order $order, bool $forShop = false, ?\Closure $downloadUrl = null): self
    {
        $shippingAddress = $order->shippingAddress();
        $lines = $order->lines();

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
            $order->customerId(),
            $forShop ? $order->transactionId() : null,
            array_values(array_map(OrderNoteView::fromNote(...), array_filter($order->notes(), static fn(OrderNote $note): bool => $forShop || $note->isForCustomer()))),
            $forShop ? array_map(static fn(OrderStatus $status): string => $status->value, $order->nextStatuses()) : [],
            $downloadUrl === null ? [] : array_map(
                static fn(DownloadGrant $grant): OrderDownloadView => OrderDownloadView::fromGrant($grant, ($lines[$grant->linePosition()] ?? null)?->name() ?? '', $downloadUrl),
                $order->grants(),
            ),
            $order->shippingOption(),
            $order->pickupPointId(),
            $order->pickupPoint(),
            (string) $order->refundedTotal(),
            $order->isShippingRefunded(),
            (string) $order->discountTotal(),
            array_map(static fn(OrderDiscount $discount): OrderDiscountView => new OrderDiscountView($discount->code, $discount->description, (string) $discount->amount, $discount->freeShipping, $discount->automatic), $order->discounts()),
            array_map(OrderFieldView::fromAnswer(...), $order->fields()),
            $forShop ? $order->attention() : null,
            $order->isPayable(),
            $order->pricesIncludeTax(),
            $order->vatNumber(),
            $order->reverseCharge(),
            $order->anonymisedAt() !== null,
        );
    }
}
