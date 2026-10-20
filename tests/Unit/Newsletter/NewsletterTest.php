<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Newsletter;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Customers\Application\Dto\ChooseEmails;
use OrillaCart\Newsletter\Application\Dto\UpdateNewsletterSettings;
use OrillaCart\Newsletter\Domain\NewsletterRuleViolation;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
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

        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'Send me the monthly letter.', ['test_list']));
        $this->order('ADA@example.com');
        $this->shop->jobs->runAll();

        $subscription = $this->shop->newsletter->subscription('ada@example.com');
        self::assertSame([true, 'checkout', 'Send me the monthly letter.'], [$subscription?->subscribed, $subscription?->source, $subscription?->consent]);
        self::assertSame(['+ada@example.com'], $this->list->heard);
        self::assertSame(['Ada', 'Lovelace', 'checkout'], [$this->list->members['ada@example.com']->firstName, $this->list->members['ada@example.com']->lastName, $this->list->members['ada@example.com']->source]);
    }

    public function testACustomerLeavesAndComesBackFromTheirAccountAgreeingAnew(): void
    {
        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'News and offers.', ['test_list']));
        $ada = $this->shop->accounts->add('ada@example.com');

        $customer = $this->shop->customers->chooseEmails($ada, new ChooseEmails(false, true));
        self::assertSame([false, true, 'account'], [$customer->cartReminders, $customer->newsletter?->subscribed, $customer->newsletter?->source]);
        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'Monthly news.', ['test_list']));
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
        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'News.'));
        $this->order('ada@example.com');
        $this->order('grace@example.com');
        self::assertSame([], $this->list->heard, 'the list is not on yet');

        $this->list->down = true;
        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'News.', ['test_list']));
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
        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'News.', ['mailchimp']));
    }

    public function testThePrivacyToolsExportTheSignUpAndEraseItEverywhere(): void
    {
        $this->shop->newsletter->updateSettings(new UpdateNewsletterSettings(true, 'News.', ['test_list']));
        $this->order('ada@example.com');

        $export = $this->shop->personalData->export('ada@example.com', 1);
        $newsletter = array_values(array_filter($export['items'], static fn(array $item): bool => $item['id'] === 'orillacart-newsletter'));
        self::assertSame('News.', array_column($newsletter[0]['data'] ?? [], 'value', 'name')['Agreed to'] ?? null);

        self::assertTrue($this->shop->personalData->erase('ada@example.com', 1)['removed']);
        $this->shop->jobs->runAll();
        self::assertNull($this->shop->newsletter->subscription('ada@example.com'));
        self::assertSame([], $this->list->members, 'the list took them off');
    }

    private function order(string $email): void
    {
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);
        $this->shop->checkout->placeOrder($owner, new PlaceOrder($email, new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer', newsletter: true));
    }
}
