<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress\Hooks;

use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Storefront\Infrastructure\WordPress\StorefrontAccountLinks;
use OrillaCart\Storefront\Infrastructure\WordPress\StorefrontOrderLinks;

/**
 * A receipt, or the page an account is made on, opens with a secret in its address: never kept by a cache, and
 * the address never sent on to the sites its links go to.
 */
#[AsHook('send_headers')]
final readonly class PrivatePages
{
    public function __invoke(): void
    {
        if (!isset($_GET[StorefrontOrderLinks::RECEIPT_QUERY]) && !isset($_GET[StorefrontAccountLinks::CONFIRM_QUERY])) {
            return;
        }
        nocache_headers();
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
    }
}
