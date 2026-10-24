<?php

declare(strict_types=1);

namespace OrillaCart\Cart\Application;

use OrillaCart\Cart\Domain\AbandonedCartRepository;
use OrillaCart\Customers\Application\PersonalDataSource;

/** The carts a person left at checkout with their email, which the shop reminds them of. */
final readonly class AbandonedCartPersonalData implements PersonalDataSource
{
    public function __construct(private AbandonedCartRepository $abandoned) {}

    public function personalData(string $email, ?int $customerId): array
    {
        $items = [];
        foreach ($this->abandoned->ofEmail(strtolower(trim($email))) as $cart) {
            $items[] = ['group' => 'orillacart-carts', 'label' => 'Carts left at checkout', 'id' => 'orillacart-cart-' . $cart->id(), 'data' => [
                ['name' => 'Left', 'value' => $cart->abandonedAt()->format(\DATE_ATOM)],
                ['name' => 'Items', 'value' => (string) $cart->items()],
                ['name' => 'Worth', 'value' => $cart->subtotal() . ' ' . $cart->currency()],
            ]];
        }

        return $items;
    }
}
