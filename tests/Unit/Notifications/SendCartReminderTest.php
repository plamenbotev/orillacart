<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Notifications;

use OrillaCart\Cart\Application\Dto\AbandonedCartView;
use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Cart\Application\Dto\CheckoutContact;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Notifications\Application\SendCartReminder;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Jobs\RecordedProgress;
use PHPUnit\Framework\TestCase;

/**
 * The cart reminder job: each reminder is settled as it is sent, whatever becomes of the next.
 */
final class SendCartReminderTest extends TestCase
{
    private TestShop $shop;
    /** @var list<int> the abandoned carts of Ada and Grace, in that order */
    private array $abandoned;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'Desk lamp');
        $this->shop->products->save(20, new SaveProduct('40', 'virtual'));
        foreach (['ada@example.com', 'grace@example.com'] as $email) {
            $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);
            $this->shop->carts->atCheckout($owner, new CheckoutContact($email, true), 'de_DE');
        }
        $this->shop->clock->modify('+5 hours');
        // Queued and refused: both wait, and can be sent by hand.
        $this->shop->mail->accepts = false;
        $this->shop->cartReminders->run();
        $this->shop->mail->accepts = true;
        $latest = $this->shop->abandonedCarts->report(new \DateTimeImmutable('2026-10-17 00:00 UTC'), new \DateTimeImmutable('2026-10-19 00:00 UTC'), 'EUR', 10)['latest'];
        usort($latest, static fn(AbandonedCartView $a, AbandonedCartView $b): int => $a->id <=> $b->id);
        $this->abandoned = array_map(static fn(AbandonedCartView $cart): int => $cart->id, $latest);
    }

    public function testEachReminderIsReportedAsItIsSent(): void
    {
        $progress = new RecordedProgress();
        self::assertSame([], $this->shop->jobs->get(SendCartReminder::TYPE)->handle([$this->job('ada', $this->abandoned[0]), $this->job('grace', $this->abandoned[1])], $progress->progress));

        self::assertSame(['ada' => 'done', 'grace' => 'done'], array_map(static fn(JobOutcome $outcome): string => $outcome->kind, $progress->outcomes));
        self::assertSame(['ada@example.com: [Lamp Shop] Your cart is waiting for you', 'grace@example.com: [Lamp Shop] Your cart is waiting for you'], $this->shop->mail->summary());
    }

    public function testAReminderThatBreaksLeavesTheOneBeforeItSettled(): void
    {
        $this->shop->mail->breaksFor = 'grace@example.com';
        $progress = new RecordedProgress();

        try {
            $this->shop->jobs->get(SendCartReminder::TYPE)->handle([$this->job('ada', $this->abandoned[0]), $this->job('grace', $this->abandoned[1])], $progress->progress);
            self::fail('the second reminder broke the mail system');
        } catch (\RuntimeException $e) {
            self::assertSame('The mail system broke.', $e->getMessage());
        }

        self::assertSame(['ada' => 'done'], array_map(static fn(JobOutcome $outcome): string => $outcome->kind, $progress->outcomes), 'the first went and was settled; the runner tries the second alone');
        self::assertSame(['ada@example.com: [Lamp Shop] Your cart is waiting for you'], $this->shop->mail->summary());
    }

    public function testARefusedOrNamelessReminderIsSettledOnItsOwn(): void
    {
        $progress = new RecordedProgress();
        $handler = $this->shop->jobs->get(SendCartReminder::TYPE);
        $handler->handle([$this->job('ada', $this->abandoned[0]), new QueuedJob('nameless', 'mail', SendCartReminder::TYPE, null, [], 1, 1)], $progress->progress);
        self::assertSame(['ada' => 'done', 'nameless' => 'fail'], array_map(static fn(JobOutcome $outcome): string => $outcome->kind, $progress->outcomes));

        $this->shop->mail->accepts = false;
        $progress = new RecordedProgress();
        $handler->handle([$this->job('refused', $this->abandoned[1])], $progress->progress);
        self::assertTrue($progress->outcomes['refused']->isRetry(), 'the mail system refused it: tried again later');
    }

    private function job(string $id, int $abandoned): QueuedJob
    {
        return new QueuedJob($id, 'mail', SendCartReminder::TYPE, null, ['abandoned' => $abandoned], 1, 1);
    }
}
