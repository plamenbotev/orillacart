<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

use OrillaCart\Customers\Application\Dto\TestDataView;
use OrillaCart\Ordering\Application\OrderService;

/**
 * The records made to try the shop out (design 62): how many there are, and their removal, test orders first
 * (an open one cancelled so its stock comes back), then the test customers' accounts. An account with the test mark
 * that is no longer one of the shop's customers (given another role since, or left the site) stays, and is not
 * counted.
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
        return new TestDataView(\count($this->orders->testOrders(10000)), \count($this->removable()));
    }

    /**
     * The test accounts removal takes: those still the shop's customers, so the count says what would go.
     *
     * @return list<int>
     */
    private function removable(): array
    {
        return array_values(array_filter($this->accounts->testIds(), $this->accounts->isShopCustomer(...)));
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
        foreach ($this->removable() as $id) {
            // What the shop kept of them (addresses, notes, groups) goes with the account.
            $this->customers->forget($id);
            $this->accounts->remove($id);
            ++$customers;
        }

        return new TestDataView($orders, $customers);
    }
}
