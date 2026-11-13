<?php

declare(strict_types=1);

namespace OrillaCart\Agents\Infrastructure\WordPress;

use OrillaCart\Agents\Application\AgentCheckouts;
use OrillaCart\Checkout\Application\CheckoutPrefills;

/** A handed-over cart's checkout starts with the buyer and address its agent gave. */
final readonly class AgentCheckoutPrefills implements CheckoutPrefills
{
    public function __construct(private AgentCheckouts $checkouts) {}

    public function forCart(#[\SensitiveParameter] string $cartToken): ?array
    {
        return $this->checkouts->given($cartToken);
    }
}
