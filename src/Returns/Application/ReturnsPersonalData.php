<?php

declare(strict_types=1);

namespace OrillaCart\Returns\Application;

use OrillaCart\Customers\Application\PersonalDataSource;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Returns\Domain\ReturnRepository;

/** The returns a person asked for on their orders: what they sent back, why, and what the shop answered. */
final readonly class ReturnsPersonalData implements PersonalDataSource
{
    /** Orders read at a time, */
    private const int PER_PAGE = 100;
    /** and at most so many of them. */
    private const int MOST_PAGES = 50;

    public function __construct(
        private ReturnRepository $returns,
        private OrderService $orders,
    ) {}

    public function personalData(string $email, ?int $customerId): array
    {
        if (trim($email) === '' && $customerId === null) {
            return [];
        }
        $items = [];
        for ($page = 1; $page <= self::MOST_PAGES; ++$page) {
            $orders = $this->orders->ofPerson($email, $customerId, $page, self::PER_PAGE);
            foreach ($orders as $order) {
                foreach ($this->returns->forOrder($order->number) as $return) {
                    $items[] = ['group' => 'orillacart-returns', 'label' => 'Returns', 'id' => 'orillacart-return-' . $return->id(), 'data' => array_values(array_filter([
                        ['name' => 'Order', 'value' => '#' . $order->number],
                        ['name' => 'Asked', 'value' => $return->requestedAt()->format(DATE_ATOM)],
                        ['name' => 'Reason', 'value' => $return->reason()->value],
                        $return->comment() === '' ? null : ['name' => 'Comment', 'value' => $return->comment()],
                        $return->answer() === '' ? null : ['name' => 'The shop\'s answer', 'value' => $return->answer()],
                    ]))];
                }
            }
            if (\count($orders) < self::PER_PAGE) {
                break;
            }
        }

        return $items;
    }
}
