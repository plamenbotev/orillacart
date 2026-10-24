<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Application;

use OrillaCart\Storefront\Application\Dto\ShopPagesView;
use OrillaCart\Storefront\Application\Dto\UpdateShopPages;
use OrillaCart\Storefront\Domain\ShopPages;
use OrillaCart\Storefront\Domain\ShopPagesRepository;
use OrillaCart\Storefront\Domain\StorefrontRuleViolation;

/**
 * Which pages the shop, cart, checkout and account are on.
 */
final readonly class ShopPagesService
{
    public function __construct(private ShopPagesRepository $pages) {}

    public function pages(): ShopPagesView
    {
        return ShopPagesView::fromPages($this->pages->load());
    }

    /**
     * @throws StorefrontRuleViolation for an id that is not a published page, or a page chosen twice
     */
    public function update(UpdateShopPages $command): ShopPagesView
    {
        foreach ([$command->shop, $command->cart, $command->checkout, $command->account] as $id) {
            if ($id !== null && !$this->pages->isPage($id)) {
                throw new StorefrontRuleViolation('Page %d is not a published page.', 'orillacart_unknown_page', values: [$id]);
            }
        }
        $pages = new ShopPages($command->shop, $command->cart, $command->checkout, $command->account);
        $this->pages->save($pages);

        return ShopPagesView::fromPages($pages);
    }
}
