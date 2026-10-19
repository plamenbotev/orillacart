<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Http\Security;

/**
 * Lets add-ons tell people from programs on the shop's public forms, as captcha plugins do.
 */
interface HumanCheck
{
    /**
     * @param string               $guard the form: accounts, coupons, checkout…
     * @param array<array-key, mixed> $body what it sent
     *
     * @return string|null why it is refused, for the visitor; null to let it through
     */
    public function refusal(string $guard, array $body): ?string;
}
