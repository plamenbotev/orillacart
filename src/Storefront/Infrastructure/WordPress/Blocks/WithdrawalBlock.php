<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Blocks;

use OrillaCart\Customers\Application\CustomerService;
use OrillaCart\Returns\Application\WithdrawalHandles;
use OrillaCart\Shared\View\TemplateRenderer;
use OrillaCart\Shared\WordPress\BlockContext;
use OrillaCart\Shared\WordPress\BlockDefinition;
use OrillaCart\Storefront\Infrastructure\WordPress\Hooks\KeptTokens;
use OrillaCart\Storefront\Infrastructure\WordPress\Shopper;
use OrillaCart\Storefront\Infrastructure\WordPress\StorefrontWithdrawalLinks;

/**
 * The withdrawal function: anyone withdraws without logging in. Asked by the order's number and email, the shop
 * emails the order's own address a link; the link opens the page again with the order's items to choose from and
 * the declaration to confirm. A logged-in customer finds their name and email filled in.
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

    public function render(array $attributes, BlockContext $context): string
    {
        $id = $this->shopper->customerId();
        $customer = $id === null ? null : $this->customers->account($id);

        $token = KeptTokens::token(StorefrontWithdrawalLinks::QUERY);
        if ($token !== null && $token !== '' && KeptTokens::kept(StorefrontWithdrawalLinks::QUERY)) {
            // The browser keeps the token for the withdrawal's actions too: the form says only which order.
            $token = WithdrawalHandles::of($token) ?? '';
        }

        return $this->templates->render('storefront/withdrawal.html.twig', [
            'email' => $customer->email ?? '',
            'name' => $customer === null ? '' : trim($customer->firstName . ' ' . $customer->lastName),
            // Opened from the link emailed to the order's address: the order's handle, '' once the browser no
            // longer keeps the token, or what the address itself says when it could not be taken out of it.
            'token' => $token,
        ]);
    }
}
