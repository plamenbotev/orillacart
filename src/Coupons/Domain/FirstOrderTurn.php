<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A customer's turn at first orders: an order with a code for first orders locks its row until it is saved, so
 * the customer's next one waits to see its uses (RedemptionRepository::takeFirstOrderTurn()). Rows are written
 * with SQL; this mapping makes the migrations create the table. Keyed by a hash of the account or the email, it
 * keeps nobody's address.
 */
#[ORM\Entity]
#[ORM\Table(name: 'coupon_first_order_turns')]
class FirstOrderTurn
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 64)]
        private string $who,
    ) {}
}
