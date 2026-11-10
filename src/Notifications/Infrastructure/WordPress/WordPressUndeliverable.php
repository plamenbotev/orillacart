<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Infrastructure\WordPress;

use OrillaCart\Notifications\Application\GuardedMailer;
use OrillaCart\Notifications\Application\Undeliverable;

/**
 * The reserved names, and what the filter orillacart_undeliverable_address says: a site adds its QA domain
 * (`add_filter('orillacart_undeliverable_address', fn($held, $to) => $held || str_ends_with($to, '@qa.shop.dev'), 10, 2)`).
 */
final readonly class WordPressUndeliverable implements Undeliverable
{
    public function address(string $address): bool
    {
        return (bool) apply_filters('orillacart_undeliverable_address', GuardedMailer::reserved($address), $address);
    }
}
