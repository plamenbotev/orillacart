<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Application;

use OrillaCart\Shared\Http\Security\Authorization;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Storefront\Domain\OpeningRepository;

/**
 * "Opening soon": while the shop is set up, visitors see that instead of its pages and cannot order; shop
 * managers see and use the real shop, to try it before it opens.
 */
final readonly class ShopOpening
{
    public function __construct(
        private OpeningRepository $opening,
        private Authorization $authorization,
    ) {}

    public function opensSoon(): bool
    {
        return $this->opening->opensSoon();
    }

    /** Whether this request's visitor sees "Opening soon": not a shop manager, while the shop is not open. */
    public function closedToVisitor(): bool
    {
        return $this->opening->opensSoon() && !$this->authorization->isGranted(Capability::MANAGE_SHOP);
    }

    public function open(): void
    {
        $this->opening->save(false);
    }

    public function close(): void
    {
        $this->opening->save(true);
    }
}
