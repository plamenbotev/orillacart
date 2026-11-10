<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

use OrillaCart\Ordering\Domain\OrderNotFound;

/**
 * What a receipt page gives its forms in place of the receipt's token: kept-NUMBER, which says only which order the
 * page shows. The token itself stays where scripts cannot read it, in a cookie the browser sends to the receipt's
 * actions (COOKIE); a customer's own order in their account needs none, as the logged-in owner is enough. So the
 * token never lands in the page, where an analytics tag or an extension reading it would hold the order.
 */
final readonly class ReceiptHandles
{
    /** The cookie the receipt's token is kept in for the receipt's actions, beside the page's own. */
    public const string COOKIE = 'orillacart_receipt_actions';
    /** A token, or the handle of a page that shows the order. */
    public const string PATTERN = ReceiptLinks::PATTERN . '|kept-[0-9]{1,10}';

    public function __construct(
        private ReceiptLinks $receipts,
        private OrderService $orders,
    ) {}

    public static function of(int $orderNumber): string
    {
        return "kept-{$orderNumber}";
    }

    /**
     * The token a form presented: itself, or for a handle the one the browser keeps for that same order (another
     * receipt opened since, in another tab, leaves this one without), or a new one for the order's logged-in owner.
     *
     * @param string|null $kept       the token the browser sent in COOKIE
     * @param int|null    $customerId the logged-in customer, if any
     *
     * @return string|null null for a handle the browser holds no token for
     */
    public function token(string $presented, #[\SensitiveParameter] ?string $kept, ?int $customerId): ?string
    {
        if (preg_match('/^kept-(\d{1,10})$/', $presented, $parts) !== 1) {
            return $presented;
        }
        $number = (int) $parts[1];
        $read = $kept === null ? null : $this->receipts->read($kept);
        if ($kept !== null && $read !== null && $read[0] === $number) {
            return $kept;
        }
        if ($customerId !== null) {
            try {
                $this->orders->customerOrder($customerId, $number);

                return $this->receipts->issue($number);
            } catch (OrderNotFound) {
            }
        }

        return null;
    }
}
