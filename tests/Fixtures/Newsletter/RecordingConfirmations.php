<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Newsletter;

use OrillaCart\Newsletter\Application\ConfirmLinks;

/** The links that confirm newsletter sign-ups, unsigned. */
final class RecordingConfirmations implements ConfirmLinks
{
    public function for(string $email): string
    {
        return 'https://shop.test/?orillacart_confirm_newsletter=' . rawurlencode($email) . '&sig=test';
    }
}
