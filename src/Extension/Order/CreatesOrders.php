<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Order;

/**
 * Places an order as the shop places one by hand (API 22): a quote accepted, a subscription's next delivery, a
 * reorder. The order is priced as checkout prices it, its stock taken, and it waits on hold for its payment
 * unless the request says it is paid; the customer is emailed as the shop's settings say.
 */
interface CreatesOrders
{
    /**
     * @throws \InvalidArgumentException for a customer that does not exist, items not sold or out of stock, an
     *                                   address the shop cannot take, a way to ship or pay it does not offer
     */
    public function create(NewOrderRequest $request): OrderSummary;
}
