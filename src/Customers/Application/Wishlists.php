<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

use OrillaCart\Customers\Domain\CustomerRuleViolation;
use OrillaCart\Storefront\Application\StorefrontSettingsService;
use OrillaCart\Storefront\Domain\WishlistPolicy;

/**
 * The products customers save for later, newest first, in their account. A visitor's wishlist lives in their
 * browser until they log in; then it joins the account's (merge).
 */
final readonly class Wishlists
{
    public const int MOST = 100;

    public function __construct(
        private Accounts $accounts,
        private StorefrontSettingsService $storefront,
    ) {}

    /**
     * @return list<int>
     *
     * @throws CustomerRuleViolation when the shop keeps no wishlists
     */
    public function of(int $customerId): array
    {
        $this->open();

        return $this->accounts->wishlist($customerId);
    }

    /**
     * @return list<int>
     *
     * @throws CustomerRuleViolation
     */
    public function add(int $customerId, int $productId): array
    {
        return $this->merge($customerId, [$productId]);
    }

    /**
     * @return list<int>
     *
     * @throws CustomerRuleViolation
     */
    public function remove(int $customerId, int $productId): array
    {
        $this->open();
        $wishlist = array_values(array_filter($this->accounts->wishlist($customerId), static fn(int $id): bool => $id !== $productId));
        $this->accounts->saveWishlist($customerId, $wishlist);

        return $wishlist;
    }

    /**
     * Puts the products first, each once; the oldest go past MOST.
     *
     * @param list<int> $productIds newest first
     *
     * @return list<int>
     *
     * @throws CustomerRuleViolation
     */
    public function merge(int $customerId, array $productIds): array
    {
        $this->open();
        $wishlist = \array_slice(array_values(array_unique([...array_filter($productIds, static fn(int $id): bool => $id > 0), ...$this->accounts->wishlist($customerId)])), 0, self::MOST);
        $this->accounts->saveWishlist($customerId, $wishlist);

        return $wishlist;
    }

    private function open(): void
    {
        if ($this->storefront->current()->wishlist === WishlistPolicy::Off) {
            throw new CustomerRuleViolation('The shop keeps no wishlists.', 'orillacart_wishlist_off');
        }
    }
}
