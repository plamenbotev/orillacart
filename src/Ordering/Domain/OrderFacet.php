<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * A value the Orders screen's filters offer: a way orders shipped, a country they went to or were billed in, a
 * currency they were paid in. Kept as orders are saved, so the screen reads a short list instead of every order;
 * a value stays when its last order goes, and then simply finds none.
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_facets')]
class OrderFacet
{
    public const string SHIPPING = 'shipping';
    public const string COUNTRY = 'country';
    public const string CURRENCY = 'currency';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 10)]
        public readonly string $kind,
        #[ORM\Id]
        #[ORM\Column(length: 200)]
        public readonly string $value,
    ) {}

    /**
     * The values an order gives the filters.
     *
     * @return list<self>
     */
    public static function of(Order $order): array
    {
        $facets = [new self(self::CURRENCY, $order->currency()), new self(self::COUNTRY, $order->billing()->country())];
        if ($order->shippingAddress() !== null) {
            $facets[] = new self(self::COUNTRY, $order->shippingAddress()->country());
        }
        $shipping = trim($order->shopShippingName() ?? '');
        if ($shipping !== '') {
            $facets[] = new self(self::SHIPPING, mb_substr($shipping, 0, 200));
        }

        return $facets;
    }
}
