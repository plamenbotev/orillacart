# 24 · Refunds

Status: built (2026-10-18). Fitted to the extension API (27): the contract is `RefundsPayments` in
`OrillaCart\Extension\Payment`, described in docs/extensions.md. Changes from the draft below:

- No `refundable()` on the gateway: the shop knows what is left (paid total minus refunds) and the provider
  refuses anything more.
- Refunds are recorded in Payment (`refunds`: method provider, manual or external; pending, accepted, done or
  failed), not in an `order_refunds` table; the order records what they covered (`refunded_total`,
  `shipping_refunded`, and per line `refunded_quantity` and `refunded_amount`).
- A refund is saved as pending before the provider is asked. One whose answer was lost stays pending: the shop
  tries it again (same idempotency key) or gives it up, and PayPal's webhook completes it if it arrives. One the
  provider accepted to finish later is accepted, and checked every hour (`ChecksRefunds`, `Refunds::reconcile`)
  until it is done or failed; another refund of the order waits until then.
- Owed (backordered) items are refunded first, as they never left the stockroom; the stock the rest took goes
  back, from the last stockroom first, when the shop puts the items back in stock.
- Paid orders are no longer cancelled or moved to Refunded from the status list: they leave by refunding, so the
  shop never keeps money for an order it called off (raised 2026-10-18).

## The contract

Payment gateways that can return money implement a second interface beside `PaymentGateway`:

```php
interface RefundingGateway
{
    /** Whether this payment can be refunded now, and up to how much (a provider may limit time or count). */
    public function refundable(PaymentRecord $payment, array $settings): RefundLimit;

    /**
     * Returns money for a payment: the whole remainder or a part. Idempotent by $refund->id, so a retried
     * request never refunds twice.
     *
     * @throws RefundRefused             the provider said no (with its reason)
     * @throws PaymentProviderUnavailable the provider could not be reached; nothing is recorded as refunded
     */
    public function refund(PaymentRecord $payment, RefundRequest $refund, array $settings): RefundResult;
}
```

- `Gateways::refunding($id): ?RefundingGateway` tells whether a method can refund. The admin shows
  "Refund through PayPal" only for those; for the others (cash on delivery, bank transfer, cheque) it offers
  "Record a refund made outside the shop".
- **PayPal** implements it with the Orders v2 capture refund API (`POST /v2/payments/captures/{id}/refund`,
  with `PayPal-Request-Id` for idempotency), and reads `PAYMENT.CAPTURE.REFUNDED` webhooks so refunds made in
  PayPal's own dashboard are recorded too.

## In the order

- **Refunds** (`refunds`, in Payment): amount, reason, by whom, when, the provider's reference, and per line how
  many items and how much, plus shipping. Order lines record `refunded_quantity` and `refunded_amount`.
- **Full or partial**: the admin picks lines and quantities (amounts follow, with their tax) or types an
  amount; shipping can be refunded or not. The total can never exceed what was paid minus earlier refunds.
- **Stock**: "Put the refunded items back in stock" (default on for physical items), through
  `StockService::giveBack` for exactly the refunded quantities; owed items are refunded first and give nothing
  back.
- **Downloads**: refunded lines stop their downloads.
- **Status**: a full refund moves the order to Refunded; a partial one keeps the status and shows "Partly
  refunded" with the amount. A note is added to the history, and the customer gets the Order refunded email
  with the refunded lines and amount.
- **Failure handling**: the refund row is written as `pending`, the provider is called, then the row becomes
  `done`, `accepted` (the provider sends the money later; checked hourly) or `failed` with the provider's
  message. A pending row whose answer was lost is the shop's to try again or give up.

## Answers (2026-10-18)

1. A partial refund may be an amount only, with a reason and no lines, for goodwill gestures; no stock goes back.
2. Refunds are the shop's action only for now; customer requests (returns) can be a later item.
