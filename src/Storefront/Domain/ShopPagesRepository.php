<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Domain;

interface ShopPagesRepository
{
    public function load(): ShopPages;

    public function save(ShopPages $pages): void;

    /** Whether the id is a published page the shop can use. */
    public function isPage(int $id): bool;
}
