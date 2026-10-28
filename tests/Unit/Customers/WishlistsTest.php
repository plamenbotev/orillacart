<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Customers;

use OrillaCart\Customers\Application\Wishlists;
use OrillaCart\Customers\Domain\CustomerRuleViolation;
use OrillaCart\Storefront\Application\StorefrontSettingsService;
use OrillaCart\Storefront\Domain\StorefrontSettings;
use OrillaCart\Storefront\Domain\WishlistPolicy;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Customers\InMemoryAccounts;
use OrillaCart\Tests\Fixtures\Storefront\InMemoryStorefrontSettings;
use PHPUnit\Framework\TestCase;

final class WishlistsTest extends TestCase
{
    private InMemoryAccounts $accounts;
    private InMemoryStorefrontSettings $settings;
    private Wishlists $wishlists;
    private int $ada;

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAccounts();
        $this->settings = new InMemoryStorefrontSettings();
        $this->wishlists = new Wishlists($this->accounts, new StorefrontSettingsService($this->settings), new TestShop()->productTranslations);
        $this->ada = $this->accounts->add('ada@example.com');
    }

    public function testTheNewestComesFirstEachOnce(): void
    {
        $this->wishlists->add($this->ada, 20);
        self::assertSame([30, 20], $this->wishlists->add($this->ada, 30));
        self::assertSame([20, 30], $this->wishlists->add($this->ada, 20), 'saved again, it comes first');
        self::assertSame([30], $this->wishlists->remove($this->ada, 20));
        self::assertSame([30], $this->wishlists->of($this->ada));
    }

    public function testAVisitorsListJoinsTheAccountsInFrontAndTheOldestGoPastTheMost(): void
    {
        $this->accounts->saveWishlist($this->ada, range(1, Wishlists::MOST));

        $merged = $this->wishlists->merge($this->ada, [500, 3, 501]);

        self::assertCount(Wishlists::MOST, $merged);
        self::assertSame([500, 3, 501, 1, 2, 4], \array_slice($merged, 0, 6));
        self::assertNotContains(Wishlists::MOST, $merged, 'the oldest left');
    }

    public function testNoWishlistsWhenTheShopKeepsNone(): void
    {
        $this->settings->settings = new StorefrontSettings(wishlist: WishlistPolicy::Off);

        $this->expectException(CustomerRuleViolation::class);
        $this->wishlists->add($this->ada, 20);
    }
}
