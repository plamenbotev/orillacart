<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Shared\Http\Security\Authorization;

/**
 * Who is looking at a storefront page: the cart in their browser's cookie, and their account when logged in.
 */
final readonly class Shopper
{
    public function __construct(private Authorization $authorization) {}

    public function cart(): CartOwner
    {
        return CartOwner::fromCookie($_COOKIE[CartOwner::COOKIE] ?? null, $this->customerId());
    }

    public function customerId(): ?int
    {
        return $this->authorization->userId();
    }
}
