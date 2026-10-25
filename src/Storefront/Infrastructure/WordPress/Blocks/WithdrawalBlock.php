<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;

/**
 * The withdrawal function: anyone withdraws without logging in, by the order's number and email; a logged-in
 * customer finds their name and email filled in.
 */
final readonly class WithdrawalBlock implements BlockDefinition
{
    public function __construct(
        private Shopper $shopper,
        private CustomerService $customers,
        private TemplateRenderer $templates,
    ) {}

    public static function name(): string
    {
        return 'orillacart/withdrawal';
    }

    public function render(array $attributes): string
    {
        $id = $this->shopper->customerId();
        $customer = $id === null ? null : $this->customers->customer($id);

        return $this->templates->render('storefront/withdrawal.html.twig', [
            'email' => $customer->email ?? '',
            'name' => $customer === null ? '' : trim($customer->firstName . ' ' . $customer->lastName),
        ]);
    }
}
