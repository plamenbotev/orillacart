<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\DownloadGrant;
use OrillaCart\Ordering\Domain\Order;
use OrillaCart\Ordering\Domain\OrderDiscount;
use OrillaCart\Ordering\Domain\OrderFee;
use OrillaCart\Ordering\Domain\OrderLine;
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
        /** What is left to give back: what was paid, less the refunds. */
        public string $refundable = '0',
        /** The language the customer ordered in; null for the site's. */
        public ?string $locale = null,
        /** @var list<NewOrderFee> what it pays besides its items and shipping, as the order's prices are */
        public array $fees = [],
        public string $feesTotal = '0',
        public string $feesTax = '0',
        /** The day the customer chose for delivery, "2026-10-21", and the time of day. */
        public ?string $deliveryDate = null,
        public ?string $deliverySlot = null,
        /** When it first left for the customer; returns count from it. */
        public ?string $fulfilledAt = null,
        /** What gift cards and store credit paid of the total, and each of them. */
        public string $giftCardTotal = '0',
        /** @var list<array{label: string, amount: string}> */
        public array $giftCards = [],
        /** What is left to pay the way the customer chose. */
        public string $amountDue = '0',
        /** @var list<OrderLineView> the shipping and the fees, as lines with their taxes and adjustments */
        public array $charges = [],
        /** All the tax charged: on the items, the shipping and the fees. */
        public string $tax = '0',
        /** @var array{reason: string, certificate: string}|null the customer's exemption from tax that covered it */
        public ?array $taxExemption = null,
        /** The tax service that worked out its taxes; null for the shop's own rates. */
        public ?string $taxProvider = null,
        /** The tax service's reference of the sale, once it recorded it. */
        public ?string $taxReference = null,
        /** @var list<PriceChangeView> the shop's and add-ons' changes to its price, each once */
        public array $priceChanges = [],
        /** The import scheme its goods came under, and the shop's number there when it charged the tax. */
        public ?string $importScheme = null,
        public ?string $importNumber = null,
        /** Priced in another currency than the shop's: the shop's, and units of the order's currency per one of it. */
        public ?string $shopCurrency = null,
        public ?string $exchangeRate = null,
        /** The business customer's own reference for the order. */
        public ?string $buyerReference = null,
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
            (string) $order->refundable(),
            $order->locale(),
            array_map(static fn(OrderFee $fee): NewOrderFee => new NewOrderFee($fee->label, (string) $fee->amount), $order->fees()),
            (string) $order->feesTotal(),
            (string) $order->feesTax(),
            $order->delivery()?->date->format('Y-m-d'),
            $order->delivery()?->slot,
            $order->fulfilledAt()?->format(\DATE_ATOM),
            (string) $order->giftCardTotal(),
            array_map(static fn(array $card): array => ['label' => $card['label'], 'amount' => (string) $card['amount']], $order->giftCards()),
            (string) $order->amountDue(),
            array_map(OrderLineView::fromLine(...), array_values(array_filter($order->allLines(), static fn(OrderLine $line): bool => !$line->isItem()))),
            (string) $order->tax(),
            $order->taxExemption(),
            $order->taxProvider(),
            $order->taxReference(),
            PriceChangeView::of($lines),
            $order->importScheme(),
            $order->importNumber(),
            $order->shopCurrency(),
            $order->exchangeRate(),
            $order->buyerReference(),
        );
    }
}
