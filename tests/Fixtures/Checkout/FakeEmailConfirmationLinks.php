<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Checkout;

use OrillaCart\Checkout\Application\EmailConfirmationLinks;

final class FakeEmailConfirmationLinks implements EmailConfirmationLinks
{
    public function url(#[\SensitiveParameter] string $token): string
    {
        return 'https://shop.test/checkout?orillacart_email=' . rawurlencode($token);
    }
}
