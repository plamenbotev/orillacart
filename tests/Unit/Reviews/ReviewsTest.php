<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Reviews;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Reviews\Application\Dto\ReviewInvitationView;
use OrillaCart\Reviews\Application\Dto\UpdateReviewSettings;
use OrillaCart\Reviews\Application\Dto\WriteReview;
use OrillaCart\Reviews\Application\Reviews;
use OrillaCart\Reviews\Domain\ReviewRuleViolation;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Reviews\InMemoryReviewSettings;
use OrillaCart\Tests\Fixtures\Reviews\InMemoryReviewStore;
use PHPUnit\Framework\TestCase;

final class ReviewsTest extends TestCase
{
    private TestShop $shop;
    private InMemoryReviewStore $store;
    private Reviews $reviews;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->shop->content->add(30, 'Album');
        $this->shop->products->save(30, new SaveProduct('12', 'virtual'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->reviews = new Reviews($this->store = new InMemoryReviewStore(), new InMemoryReviewSettings(), $this->shop->orders, $this->shop->accounts);
    }

    public function testAnyoneReviewsOnceAndABuyersReviewIsMarked(): void
    {
        $ada = $this->shop->accounts->add('ada@example.com', 'Ada', 'Lovelace');
        $this->order(20, 'ada@example.com', $ada, paid: true);

        $invitation = $this->reviews->invitation(20, $ada);
        self::assertTrue($invitation->open);
        self::assertSame('Ada L.', $invitation->author, 'the first name and the first letter of the last');
        self::assertTrue($invitation->verified);

        $written = $this->reviews->write(20, new WriteReview(5, 'Read it twice.'), $ada);
        self::assertSame('published', $written->status);
        self::assertTrue($written->review->verified);
        self::assertSame(ReviewInvitationView::REVIEWED, $this->reviews->invitation(20, $ada)->reason);

        $guest = $this->reviews->write(20, new WriteReview(2, 'Too short.', 'Bob', 'bob@example.com'), null);
        self::assertFalse($guest->review->verified, 'a guest\'s email is not proof of buying');
        self::assertSame('Bob', $guest->review->author);

        $this->expectException(ReviewRuleViolation::class);
        $this->reviews->write(20, new WriteReview(1, 'Again.', 'Bobby', 'BOB@example.com'), null);
    }

    public function testAnUnpaidOrderMakesNoBuyer(): void
    {
        $ada = $this->shop->accounts->add('ada@example.com');
        $this->order(20, 'ada@example.com', $ada, paid: false);

        self::assertFalse($this->reviews->invitation(20, $ada)->verified);
        self::assertFalse($this->reviews->invitation(30, $ada)->verified);
    }

    public function testTheLinkToAReceiptMakesAGuestABuyerOfWhatTheyPaidFor(): void
    {
        $number = $this->order(20, 'grace@example.com', null, paid: true);
        $receipt = $this->shop->receipts->issue($number);

        $invitation = $this->reviews->invitation(20, null, $receipt);
        self::assertSame(['Grace H.', true], [$invitation->author, $invitation->verified]);
        self::assertFalse($this->reviews->invitation(30, null, $receipt)->verified, 'not a product of the order');

        $written = $this->reviews->write(20, new WriteReview(4, 'Good.', 'Someone else', 'other@example.com', $receipt), null);
        self::assertSame(['Grace H.', true], [$written->review->author, $written->review->verified], 'the order says who wrote it');
        self::assertSame('grace@example.com', $this->store->reviews[0][1]->authorEmail);

        $this->expectException(ReviewRuleViolation::class);
        $this->reviews->write(30, new WriteReview(4, 'Good.', receipt: '1-1-' . str_repeat('0', 32)), null);
    }

    public function testOnlyBuyersWhenTheShopSaysSo(): void
    {
        $this->reviews->updateSettings(new UpdateReviewSettings('buyers'));
        $ada = $this->shop->accounts->add('ada@example.com');

        self::assertSame(ReviewInvitationView::LOG_IN, $this->reviews->invitation(20, null)->reason, 'a guest logs in, or comes from their receipt');
        self::assertSame(ReviewInvitationView::BUYERS_ONLY, $this->reviews->invitation(20, $ada)->reason);
        try {
            $this->reviews->write(20, new WriteReview(5, 'Great.'), $ada);
            self::fail('a customer who did not buy it reviewed it');
        } catch (ReviewRuleViolation $e) {
            self::assertSame('orillacart_reviews_buyers_only', $e->errorCode);
        }

        $this->order(20, 'ada@example.com', $ada, paid: true);
        self::assertTrue($this->reviews->invitation(20, $ada)->open);
    }

    public function testNoReviewsWhenOffOrClosedAndTheSiteMayAskGuestsToLogIn(): void
    {
        $this->store->closed = [30];
        self::assertSame(ReviewInvitationView::CLOSED, $this->reviews->invitation(30, null)->reason);

        $this->store->loginRequired = true;
        self::assertSame(ReviewInvitationView::LOG_IN, $this->reviews->invitation(20, null)->reason, 'Settings → Discussion asks commenters to log in');

        $this->reviews->updateSettings(new UpdateReviewSettings('off'));
        self::assertFalse($this->reviews->shown());
        self::assertSame(ReviewInvitationView::OFF, $this->reviews->invitation(20, null)->reason);
        $this->expectException(ReviewRuleViolation::class);
        $this->reviews->write(20, new WriteReview(5, 'Great.', 'Bob', 'bob@example.com'), null);
    }

    public function testThePageAndTheStarsCountOnlyPublishedReviews(): void
    {
        foreach ([5, 4, 4] as $i => $stars) {
            $this->reviews->write(20, new WriteReview($stars, 'Fine.', "Reader {$i}", "reader{$i}@example.com"), null);
        }
        $this->store->hold = true;
        $held = $this->reviews->write(20, new WriteReview(1, 'Bad.', 'Troll', 'troll@example.com'), null);
        self::assertSame('held', $held->status);

        $page = $this->reviews->page(20);
        self::assertNotNull($page->rating);
        self::assertSame(['4.3', 3, [5 => 1, 4 => 2, 3 => 0, 2 => 0, 1 => 0]], [$page->rating->average, $page->rating->count, $page->rating->stars]);
        self::assertSame(['Reader 2', 'Reader 1', 'Reader 0'], array_map(static fn($review): string => $review->author, $page->reviews), 'newest first');
        self::assertSame([20], array_keys($this->reviews->ratings([20, 30])), 'products without reviews are left out');
    }

    private function order(int $productId, string $email, ?int $customerId, bool $paid): int
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null, $customerId), new AddToCart($productId))->newToken, $customerId);
        $placed = $this->shop->checkout->placeOrder($owner, new PlaceOrder($email, new AddressData($email === 'grace@example.com' ? 'Grace' : 'Ada', $email === 'grace@example.com' ? 'Hopper' : 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'));
        if ($paid) {
            $this->shop->orders->markPaid($placed->number, 'T-1');
        }

        return $placed->number;
    }
}
