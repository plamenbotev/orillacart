<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Infrastructure\WordPress\Hooks;

use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * A user deleted in WordPress stops being a customer of this shop. On a network, removing a user from one site
 * fires the same action, but they keep their account and so their orders.
 */
#[AsHook('deleted_user')]
final readonly class ForgetDeletedUsers
{
    public function __construct(private CustomerService $customers) {}

    public function __invoke(int $id): void
    {
        if (get_userdata($id) !== false) {
            return;
        }
        $this->customers->forget($id);
    }
}
