<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

use OrillaCart\Customers\Application\Dto\TestDataView;
use OrillaCart\Ordering\Application\OrderService;

/**
 * The records made to try the shop out (design 62): how many there are, and their removal, test orders first
 * (an open one cancelled so its stock comes back), then the test customers' accounts. An account with the test mark
 * that is no longer one of the shop's customers (made staff since, or left the site) stays.
 */
final readonly class TestData
{
    public function __construct(
        private OrderService $orders,
        private Accounts $accounts,
        private CustomerService $customers,
    ) {}

    public function count(): TestDataView
    {
        return new TestDataView(\count($this->orders->testOrders(10000)), \count($this->accounts->testIds()));
    }

    public function remove(): TestDataView
    {
        $orders = 0;
        do {
            $batch = $this->orders->testOrders();
            foreach ($batch as $number) {
                $this->orders->removeTest($number);
                ++$orders;
            }
        } while ($batch !== []);
        $customers = 0;
        foreach ($this->accounts->testIds() as $id) {
            if (!$this->accounts->isShopCustomer($id)) {
                continue;
            }
            // What the shop kept of them (addresses, notes, groups) goes with the account.
            $this->customers->forget($id);
            $this->accounts->remove($id);
            ++$customers;
        }

        return new TestDataView($orders, $customers);
    }
}
