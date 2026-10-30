<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Newsletter;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Customers\Application\Dto\ChooseEmails;
use OrillaCart\Jobs\Application\Dto\JobOutcome;
use OrillaCart\Jobs\Application\Dto\QueuedJob;
use OrillaCart\Newsletter\Application\Dto\UpdateNewsletterSettings;
use OrillaCart\Newsletter\Application\SyncSubscriber;
use OrillaCart\Newsletter\Domain\NewsletterRuleViolation;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Jobs\RecordedProgress;
use OrillaCart\Tests\Fixtures\Newsletter\ForgettingMailingList;
use OrillaCart\Tests\Fixtures\Newsletter\RecordingMailingList;
use PHPUnit\Framework\TestCase;

final class NewsletterTest extends TestCase
{
    private TestShop $shop;
    private RecordingMailingList $list;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->shop->extensions->mailingLists[] = $this->list = new RecordingMailingList();
    }

    public function testTickingTheBoxAtCheckoutSignsUpWithItsWordsAndTheChosenListsHear(): void
    {
        $this->order('ada@example.com');
        self::assertSame(0, $this->shop->newsletter->settings()->subscribers, 'no newsletter offered, no sign-up');

        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'Send me the monthly letter.', ['test_list'], confirmNew: false));
        $this->order('ADA@example.com');
        $this->shop->jobs->runAll();

        $subscription = $this->shop->newsletter->subscription('ada@example.com');
        self::assertSame([true, 'checkout', 'Send me the monthly letter.'], [$subscription?->subscribed, $subscription?->source, $subscription?->consent]);
        self::assertSame(['+ada@example.com'], $this->list->heard);
        self::assertSame(['Ada', 'Lovelace', 'checkout'], [$this->list->members['ada@example.com']->firstName, $this->list->members['ada@example.com']->lastName, $this->list->members['ada@example.com']->source]);
    }

    public function testACustomerLeavesAndComesBackFromTheirAccountAgreeingAnew(): void
    {
        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'News and offers.', ['test_list'], confirmNew: false));
        $ada = $this->shop->accounts->add('ada@example.com');

        $customer = $this->shop->customers->chooseEmails($ada, new ChooseEmails(false, true));
        self::assertSame([false, true, 'account'], [$customer->cartReminders, $customer->newsletter?->subscribed, $customer->newsletter?->source]);
        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'Monthly news.', ['test_list'], confirmNew: false));
        $this->shop->customers->chooseEmails($ada, new ChooseEmails(true, true));
        self::assertSame('News and offers.', $this->shop->newsletter->subscription('ada@example.com')?->consent, 'still subscribed: the first words stand');

        $this->shop->customers->chooseEmails($ada, new ChooseEmails(true, false));
        $customer = $this->shop->customers->chooseEmails($ada, new ChooseEmails(true, true));
        $this->shop->jobs->runAll();

        self::assertSame([true, 'Monthly news.'], [$customer->cartReminders, $customer->newsletter?->consent]);
        self::assertSame(['+ada@example.com', '-ada@example.com', '+ada@example.com'], $this->list->heard);
    }

    public function testAListTurnedOnGetsEveryoneSoFarAndOneThatIsDownIsAskedAgain(): void
    {
        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'News.', confirmNew: false));
        $this->order('ada@example.com');
        $this->order('grace@example.com');
        self::assertSame([], $this->list->heard, 'the list is not on yet');

        $this->list->down = true;
        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'News.', ['test_list'], confirmNew: false));
        $this->shop->jobs->runAll();
        self::assertSame([], $this->list->heard);

        $this->list->down = false;
        $this->shop->clock->modify('+1 hour');
        $this->shop->jobs->runAll();
        self::assertEqualsCanonicalizing(['+ada@example.com', '+grace@example.com'], $this->list->heard);
    }

    public function testOnlyAListAnAddOnBringsIsChosen(): void
    {
        $this->expectException(NewsletterRuleViolation::class);
        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'News.', ['mailchimp'], confirmNew: false));
    }

    public function testThePrivacyToolsExportTheSignUpAndEraseItEverywhere(): void
    {
        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'News.', ['test_list'], confirmNew: false));
        $this->order('ada@example.com');

        $export = $this->shop->personalData->export('ada@example.com', 1);
        $newsletter = array_values(array_filter($export['items'], static fn(array $item): bool => $item['id'] === 'orillacart-newsletter'));
        self::assertSame('News.', array_column($newsletter[0]['data'] ?? [], 'value', 'name')['Agreed to'] ?? null);

        self::assertTrue($this->shop->personalData->erase('ada@example.com', 1)['removed']);
        $this->shop->jobs->runAll();
        self::assertNull($this->shop->newsletter->subscription('ada@example.com'));
        self::assertSame([], $this->list->members, 'the list took them off');
    }

    public function testErasingDeletesThePersonAtEveryListTheShopKnows(): void
    {
        $forgetting = new ForgettingMailingList();
        $this->shop->extensions->mailingLists[] = $forgetting;
        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'News.', ['test_list'], confirmNew: false));
        $this->order('ada@example.com');
        $this->shop->jobs->runAll();

        $this->shop->personalData->erase('ada@example.com', 1);
        $this->shop->jobs->runAll();

        self::assertSame(['ada@example.com'], $forgetting->forgotten, 'a list the sign-ups no longer go to deletes them too');
        self::assertSame([], $this->list->members, 'one that cannot delete takes them off');
    }

    public function testOneWhoLeftAtTheListIsOffTheShopsNewsletterAndNoListSignsThemUpAgain(): void
    {
        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'News.', ['test_list'], confirmNew: false));
        $this->order('ada@example.com');
        $this->shop->jobs->runAll();

        $this->shop->newsletter->unsubscribedAtList('ada@example.com');
        $other = new RecordingMailingList('other_list');
        $this->shop->extensions->mailingLists[] = $other;
        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'News.', ['test_list', 'other_list'], confirmNew: false));
        $this->shop->jobs->runAll();

        self::assertFalse($this->shop->newsletter->isSubscribed('ada@example.com'));
        self::assertSame([], $other->members);
    }

    public function testASignUpFollowsAChangedEmail(): void
    {
        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'News.', ['test_list'], confirmNew: false));
        $this->order('ada@example.com');
        $this->shop->jobs->runAll();

        $this->shop->newsletter->changeEmail('ada@example.com', 'Ada@New.example');
        $this->shop->jobs->runAll();

        self::assertTrue($this->shop->newsletter->isSubscribed('ada@new.example'));
        self::assertFalse($this->shop->newsletter->isSubscribed('ada@example.com'));
        self::assertSame(['ada@new.example'], array_keys($this->list->members), 'the old address left the list, the new one joined');
    }

    public function testANewSignUpWaitsForItsOwnerToConfirmItFromTheEmailBeforeAnyListHears(): void
    {
        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'News.', ['test_list']));
        self::assertTrue($this->shop->newsletter->settings()->confirmNew, 'on unless the shop turns it off');
        $this->shop->languages->locale = 'de_DE';
        $this->order('ada@example.com');
        $this->shop->jobs->runAll();

        self::assertFalse($this->shop->newsletter->isSubscribed('ada@example.com'));
        self::assertTrue($this->shop->newsletter->isPending('ada@example.com'));
        self::assertSame(0, $this->shop->newsletter->settings()->subscribers);
        self::assertSame([], $this->list->heard, 'no list hears of an address nobody proved is theirs');
        $asked = array_values(array_filter($this->shop->mail->emails, static fn($email): bool => $email->kind === 'newsletter_confirm'));
        self::assertSame(['ada@example.com'], array_map(static fn($email): string => $email->to, $asked), 'asked to confirm');
        self::assertStringContainsString('orillacart_confirm_newsletter=ada%40example.com', $asked[0]->html);
        self::assertContains('de_DE', $this->shop->languages->switched, 'in the language they signed up in');
        self::assertTrue($this->shop->newsletter->subscription('ada@example.com')?->pending);

        self::assertTrue($this->shop->newsletter->confirm('ADA@example.com'));
        self::assertFalse($this->shop->newsletter->confirm('ada@example.com'), 'once');
        $this->shop->jobs->runAll();

        self::assertTrue($this->shop->newsletter->isSubscribed('ada@example.com'));
        self::assertSame(['+ada@example.com'], $this->list->heard);
        self::assertNotNull($this->shop->newsletter->subscription('ada@example.com')?->confirmedAt);
    }

    public function testAnUnsubscribedSignUpIsNotConfirmedByAnOldLink(): void
    {
        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'News.', ['test_list']));
        $this->order('ada@example.com');
        $this->shop->newsletter->unsubscribe('ada@example.com');

        self::assertFalse($this->shop->newsletter->confirm('ada@example.com'));
        self::assertFalse($this->shop->newsletter->isSubscribed('ada@example.com'));
    }

    public function testAnEmailChangedToOneAlreadySignedUpLeavesTheOldAddressOff(): void
    {
        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'News.', ['test_list'], confirmNew: false));
        $this->order('ada@example.com');
        $this->order('ada@new.example');
        $this->shop->jobs->runAll();

        $this->shop->newsletter->changeEmail('ada@example.com', 'ada@new.example');
        $this->shop->jobs->runAll();

        self::assertFalse($this->shop->newsletter->isSubscribed('ada@example.com'), 'not left subscribed at the address given up');
        self::assertNull($this->shop->newsletter->subscription('ada@example.com'));
        self::assertTrue($this->shop->newsletter->isSubscribed('ada@new.example'));
        self::assertSame(['ada@new.example'], array_keys($this->list->members));
    }

    public function testAnEmailChangedToOneThatLeftMovesTheSubscriptionThere(): void
    {
        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'News.', ['test_list'], confirmNew: false));
        $this->order('ada@new.example');
        $this->shop->newsletter->unsubscribe('ada@new.example');
        $this->order('ada@example.com');
        $this->shop->jobs->runAll();

        $this->shop->newsletter->changeEmail('ada@example.com', 'ada@new.example');
        $this->shop->jobs->runAll();

        self::assertNull($this->shop->newsletter->subscription('ada@example.com'));
        self::assertTrue($this->shop->newsletter->isSubscribed('ada@new.example'), 'the subscribed sign-up moved to the new address');
        self::assertSame(['ada@new.example'], array_keys($this->list->members));
    }

    private function order(string $email): void
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);
        $this->shop->checkout->placeOrder($owner, new PlaceOrder($email, new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer', newsletter: true));
    }

    public function testEachListIsReportedAsItIsToldAndOneThatIsDownWaitsAlone(): void
    {
        $other = new RecordingMailingList('other_list');
        $this->shop->extensions->mailingLists[] = $other;
        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'News.', ['test_list', 'other_list'], confirmNew: false));
        $this->order('ada@example.com');
        $this->shop->jobs->runAll();
        [$heard, $otherHeard] = [\count($this->list->heard), \count($other->heard)];
        $other->down = true;
        $job = static fn(string $id, string $list): QueuedJob => new QueuedJob($id, 'mail', SyncSubscriber::TYPE, null, ['email' => 'ada@example.com', 'list' => $list], 1, 1);

        $progress = new RecordedProgress();
        self::assertSame([], $this->shop->jobs->get(SyncSubscriber::TYPE)->handle([$job('first', 'test_list'), $job('second', 'other_list'), $job('third', 'gone_list')], $progress->progress));

        self::assertSame(['first' => 'done', 'second' => 'retry', 'third' => 'done'], array_map(static fn(JobOutcome $outcome): string => $outcome->kind, $progress->outcomes), 'the first list was settled before the second was asked; a list gone is left alone');
        self::assertSame([['+ada@example.com'], []], [\array_slice($this->list->heard, $heard), \array_slice($other->heard, $otherHeard)]);
    }
}
