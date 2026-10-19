<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Infrastructure\WordPress;

use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Shared\WordPress\ScheduledTask;

/**
 * Daily: forgets the accounts asked for whose links stopped working.
 */
final readonly class ForgetUnconfirmedAccounts implements ScheduledTask
{
    public function __construct(private CustomerService $customers) {}

    public static function hook(): string
    {
        return 'orillacart_forget_unconfirmed_accounts';
    }

    public static function recurrence(): string
    {
        return 'daily';
    }

    public function run(): void
    {
        $this->customers->forgetUnconfirmed();
    }
}
