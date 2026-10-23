<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\References;

/**
 * An add-on's mark on an order, such as "sent to the warehouse": recorded when it happens, so the Orders screen
 * filters by it in the database however many orders have it. Gone with the order.
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_marks')]
#[ORM\UniqueConstraint(name: 'order_mark', columns: ['order_id', 'mark'])]
#[ORM\Index(name: 'mark_order', columns: ['mark', 'order_id'])]
class MarkedOrder
{
    /** As add-ons' filter ids are. */
    public const string MARK = '/^[a-z][a-z0-9_]{0,39}$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column]
        #[References('orders', 'CASCADE')]
        private int $orderId,
        #[ORM\Column(length: 40)]
        private string $mark,
    ) {
        if (preg_match(self::MARK, $mark) !== 1) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a filter id: lowercase letters, digits and underscores, up to 40.', $mark));
        }
    }

    public function orderId(): int
    {
        return $this->orderId;
    }

    public function mark(): string
    {
        return $this->mark;
    }
}
