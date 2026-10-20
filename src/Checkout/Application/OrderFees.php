<?php

declare(strict_types=1);

namespace OrillaCart\Checkout\Application;

use OrillaCart\Extension\Checkout\FeeQuery;
use OrillaCart\Extension\FailsClosed;
use OrillaCart\Payment\Application\PaymentOptions;
use OrillaCart\Shared\Domain\Money;
use OrillaCart\Shared\Extension\ExtensionFailures;
use OrillaCart\Shared\Extension\InstalledExtensions;
use Psr\Log\LoggerInterface;

/**
 * The fees an order pays besides its items and shipping: the one the shop set on the chosen way to pay, then those
 * add-ons add (OrderFee), in the order they were registered. A fee that fails is left out and the order says so; one
 * that fails closed has checkout refuse the order.
 *
 * @phpstan-type PricedFee array{label: string, amount: Money, taxable: bool, tax_group_id: ?int}
 */
final readonly class OrderFees
{
    private const int MAX_LABEL_LENGTH = 100;

    public function __construct(
        private PaymentOptions $payments,
        private InstalledExtensions $extensions,
        private LoggerInterface $logger,
        private ExtensionFailures $failures,
    ) {}

    /**
     * @return list<PricedFee> each for more than nothing
     */
    public function of(FeeQuery $query): array
    {
        $fees = [];
        // Nothing to pay, nothing for paying.
        $base = Money::of($query->subtotal)->add(Money::of($query->shipping));
        $gateway = $base->isZero() ? null : $query->paymentMethod;
        $payment = $gateway === null ? null : $this->payments->fee($gateway);
        $amount = $gateway === null ? null : $this->paymentFee($gateway, $base);
        if ($payment !== null && $amount !== null) {
            $fees[] = ['label' => $payment->label, 'amount' => $amount, 'taxable' => true, 'tax_group_id' => null];
        }
        foreach ($this->extensions->orderFees() as $orderFee) {
            try {
                $fee = $orderFee->fee($query);
            } catch (\Throwable $e) {
                $this->logger->error('The order fee {fee} failed: {message}', ['fee' => $orderFee->id(), 'message' => $e->getMessage(), 'exception' => $e]);
                $this->failures->record(\sprintf('The order fee %s failed; the order was priced without it.', $orderFee->id()), $orderFee instanceof FailsClosed);
                continue;
            }
            if ($fee === null) {
                continue;
            }
            $label = trim($fee->label);
            if (preg_match('/^-?\d{1,11}(\.\d{1,' . Money::DECIMALS . '})?$/', $fee->amount) !== 1 || $label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
                $this->logger->error('The order fee {fee} answered "{label}: {amount}", which is not a label of up to {longest} characters and an amount; it is left out.', ['fee' => $orderFee->id(), 'label' => $fee->label, 'amount' => $fee->amount, 'longest' => self::MAX_LABEL_LENGTH]);
                $this->failures->record(\sprintf('The order fee %s gave no fee the shop can charge; the order was priced without it.', $orderFee->id()), $orderFee instanceof FailsClosed);
                continue;
            }
            $amount = Money::of($fee->amount);
            if ($amount->compare(Money::zero()) > 0) {
                $fees[] = ['label' => $label, 'amount' => $amount, 'taxable' => $fee->taxable, 'tax_group_id' => $fee->taxGroupId];
            }
        }

        return $fees;
    }

    /**
     * What paying with the method adds to an order of this much (the items, less discounts, and shipping), before
     * tax is added; null for nothing.
     */
    public function paymentFee(string $gateway, Money $base): ?Money
    {
        $fee = $this->payments->fee($gateway);
        $amount = $fee === null ? Money::zero() : Money::of($fee->fixed)->add($base->atLeastZero()->percent($fee->percent));

        return $amount->compare(Money::zero()) > 0 ? $amount : null;
    }
}
