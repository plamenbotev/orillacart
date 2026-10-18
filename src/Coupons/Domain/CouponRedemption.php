<?php

declare(strict_types=1);

namespace OrillaCart\Coupons\Domain;

use Doctrine\ORM\Mapping as ORM;
use OrillaCart\Shared\Domain\Money;

/**
 * An order that used a coupon, while it stands: who placed it and how much the coupon took off.
 */
#[ORM\Entity]
#[ORM\Table(name: 'coupon_redemptions')]
#[ORM\UniqueConstraint(name: 'redemption_order', columns: ['coupon_id', 'order_id'])]
#[ORM\Index(name: 'redemption_customer', columns: ['coupon_id', 'customer_id'])]
#[ORM\Index(name: 'redemption_email', columns: ['coupon_id', 'email'])]
#[ORM\Index(name: 'redemption_of_order', columns: ['order_id'])]
class CouponRedemption
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'decimal', precision: Money::PRECISION, scale: Money::DECIMALS)]
    private string $amount;

    public function __construct(
        #[ORM\Column]
        private int $couponId,
        #[ORM\Column]
        private int $orderId,
        #[ORM\Column(nullable: true)]
        private ?int $customerId,
        #[ORM\Column(length: 200)]
        private string $email,
        Money $amount,
        #[ORM\Column]
        private \DateTimeImmutable $redeemedAt,
    ) {
        $this->email = mb_strtolower($email);
        $this->amount = $amount->toFixed();
    }

    public function couponId(): int
    {
        return $this->couponId;
    }

    public function orderId(): int
    {
        return $this->orderId;
    }

    public function amount(): Money
    {
        return Money::of($this->amount);
    }
}
