<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Storefront;

use OrillaCart\Storefront\Application\Dto\UpdateShopPages;
use OrillaCart\Storefront\Application\ShopPagesService;
use OrillaCart\Storefront\Domain\ShopPages;
use OrillaCart\Storefront\Domain\ShopPagesRepository;
use OrillaCart\Storefront\Domain\StorefrontRuleViolation;
use PHPUnit\Framework\TestCase;

final class ShopPagesServiceTest extends TestCase
{
    public function testEachPartOfTheShopIsOnAPublishedPageOfItsOwn(): void
    {
        $repository = new class implements ShopPagesRepository {
            public ShopPages $pages;

            public function __construct()
            {
                $this->pages = new ShopPages();
            }

            public function load(): ShopPages
            {
                return $this->pages;
            }

            public function save(ShopPages $pages): void
            {
                $this->pages = $pages;
            }

            public function isPage(int $id): bool
            {
                return $id < 100;
            }
        };
        $service = new ShopPagesService($repository);

        $saved = $service->update(new UpdateShopPages(1, 2, 3, null));
        self::assertSame([1, 2, 3, null], [$saved->shop, $saved->cart, $saved->checkout, $saved->account]);

        foreach ([[new UpdateShopPages(1, 1), 'orillacart_page_used_twice'], [new UpdateShopPages(cart: 100), 'orillacart_unknown_page']] as [$command, $code]) {
            try {
                $service->update($command);
                self::fail('Refused: ' . $code);
            } catch (StorefrontRuleViolation $e) {
                self::assertSame($code, $e->errorCode);
            }
        }
        self::assertSame(2, $service->pages()->cart, 'refused changes change nothing');
    }
}
