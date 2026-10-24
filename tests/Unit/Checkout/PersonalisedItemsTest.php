<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartLineView;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Cart\Domain\CartRuleViolation;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\EditedItem;
use OrillaCart\Checkout\Application\Dto\EditOrderItems;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Checkout\Domain\CheckoutRuleViolation;
use OrillaCart\Forms\Application\Dto\SaveFieldChoice;
use OrillaCart\Forms\Application\Dto\SaveFieldCondition;
use OrillaCart\Forms\Application\Dto\SaveForm;
use OrillaCart\Forms\Application\Dto\SaveFormField;
use OrillaCart\Forms\Application\Dto\SendUpload;
use OrillaCart\Forms\Application\FormUploads;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shared\Domain\InvalidFields;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * A product's forms on its page: the answers go with the item, in the cart and the order, and cost what the shop
 * priced them.
 */
final class PersonalisedItemsTest extends TestCase
{
    private TestShop $shop;
    private int $form;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'Ring');
        $this->shop->content->terms[20] = ['categories' => [4], 'brands' => []];
        $this->shop->products->save(20, new SaveProduct('100', 'virtual'));
        $this->shop->content->add(30, 'Card');
        $this->shop->products->save(30, new SaveProduct('5', 'virtual'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->form = $this->shop->forms->create(new SaveForm('Engraving', scope: 'categories', categoryIds: [4], fields: [
            new SaveFormField('Engrave it', 'choice', 'engrave', required: true, choices: [new SaveFieldChoice('Yes', 'yes', '15'), new SaveFieldChoice('No', 'no')]),
            new SaveFormField('Text', key: 'text', required: true, longest: 20, condition: new SaveFieldCondition('engrave', ['yes'])),
            new SaveFormField('Gift box', 'checkbox', 'box', price: '4.50'),
        ]))->id;
    }

    public function testTheAnswersGoWithTheItemAndCostWhatTheShopPricedThem(): void
    {
        $owner = $this->add(['engrave' => 'yes', 'text' => 'For Ada', 'box' => true]);
        $owner = $this->add(['engrave' => 'yes', 'text' => 'For Ada', 'box' => true], $owner);
        $owner = $this->add(['engrave' => 'no', 'text' => 'not asked'], $owner);
        $this->shop->carts->add($owner, new AddToCart(30, personalisation: ["f{$this->form}_engrave" => 'yes']));

        $lines = $this->shop->carts->cart($owner)->cart->lines;
        self::assertSame([['Ring', 2, '119.5'], ['Ring', 1, '100'], ['Card', 1, '5']], array_map(static fn(CartLineView $line): array => [$line->name, $line->quantity, $line->unitPrice], $lines), 'the same answers add to their line; other answers make another');
        self::assertSame([['label' => 'Engrave it', 'answer' => 'Yes', 'price' => '15'], ['label' => 'Text', 'answer' => 'For Ada', 'price' => null], ['label' => 'Gift box', 'answer' => 'Yes', 'price' => '4.5']], $lines[0]->personalisation);
        self::assertSame([['label' => 'Engrave it', 'answer' => 'No', 'price' => null]], $lines[1]->personalisation, 'a question not asked keeps no answer');
        self::assertSame([], $lines[2]->personalisation, 'the card has no form');

        $order = $this->shop->orders->order($this->place($owner));
        self::assertSame('344', $order->total);
        self::assertSame(['119.5', '239'], [$order->lines[0]->unitPrice, $order->lines[0]->total]);
        self::assertSame([
            ['id' => "f{$this->form}_box", 'label' => 'Gift box', 'answer' => 'Yes', 'value' => '1', 'price' => '4.50', 'upload_id' => null],
            ['id' => "f{$this->form}_engrave", 'label' => 'Engrave it', 'answer' => 'Yes', 'value' => 'yes', 'price' => '15', 'upload_id' => null],
            ['id' => "f{$this->form}_text", 'label' => 'Text', 'answer' => 'For Ada', 'value' => 'For Ada', 'price' => null, 'upload_id' => null],
        ], self::sorted($order->lines[0]->personalisation));
    }

    public function testRefusedAnswersAreReportedNextToTheirFields(): void
    {
        try {
            $this->add(['engrave' => 'yes', 'text' => str_repeat('x', 21), 'box' => true]);
            self::fail('refused answers');
        } catch (InvalidFields $e) {
            self::assertSame([['field' => "personalisation.f{$this->form}_text", 'message' => 'Up to 20 characters.']], $e->messages());
        }
        try {
            $this->add([]);
            self::fail('a required answer missing');
        } catch (InvalidFields $e) {
            self::assertSame([['field' => "personalisation.f{$this->form}_engrave", 'message' => 'This field is required.']], $e->messages());
        }
    }

    public function testALineWhoseFormChangedCannotBeOrderedUntilAddedAgain(): void
    {
        $owner = $this->add(['engrave' => 'no']);
        $this->shop->forms->update($this->form, new SaveForm('Engraving', scope: 'categories', categoryIds: [4], fields: [
            new SaveFormField('Engrave it', 'choice', 'engrave', required: true, choices: [new SaveFieldChoice('Yes', 'yes', '15')]),
        ]));

        $line = $this->shop->carts->cart($owner)->cart->lines[0];
        self::assertSame([CartLineView::CHANGED, CartService::CHANGED], [$line->problem, $line->message]);
        self::assertFalse($this->shop->carts->cart($owner)->cart->orderable);
        $this->expectException(CheckoutRuleViolation::class);
        $this->place($owner);
    }

    public function testEditingTheOrderKeepsTheAnswersOfTheLinesItKeeps(): void
    {
        $number = $this->place($this->add(['engrave' => 'yes', 'text' => 'For Ada']));

        $edited = $this->shop->editing->edit($number, new EditOrderItems([new EditedItem(20, 3, line: 0), new EditedItem(30, 1)]), 7);
        self::assertSame(['For Ada', []], [$edited->lines[0]->personalisation[1]['answer'] ?? null, $edited->lines[1]->personalisation]);
        self::assertSame('115', $edited->lines[0]->unitPrice, 'the catalog price and the answers');
        self::assertSame([], $this->shop->editing->edit($number, new EditOrderItems([new EditedItem(30, 1, line: 0)]), 7)->lines[0]->personalisation, 'another product keeps none');
    }

    public function testOrderingAgainBringsTheAnswersBackWhileTheyHold(): void
    {
        $number = $this->place($this->add(['engrave' => 'yes', 'text' => 'For Ada']));

        $view = $this->shop->reorder->fromReceipt(new CartOwner(null), $this->shop->receipts->issue($number));
        self::assertSame([], $view->missed);
        self::assertSame(['Yes', 'For Ada'], array_column($view->result->cart->lines[0]->personalisation, 'answer'));
    }

    public function testAnonymisingTheOrderBlanksTheAnswers(): void
    {
        $number = $this->place($this->add(['engrave' => 'yes', 'text' => 'For Ada']));

        $order = $this->shop->orders->anonymise($number, 7);
        self::assertSame(['', ''], array_column($order->lines[0]->personalisation, 'answer'));
        self::assertSame(['Engrave it', 'Text'], array_column(self::sorted($order->lines[0]->personalisation), 'label'), 'the questions and their prices stay');
    }

    public function testAFileIsNamedByItsTokenAndHeldByTheCart(): void
    {
        $logo = $this->shop->forms->create(new SaveForm('Print', scope: 'products', productIds: [30], fields: [
            new SaveFormField('Logo', 'file', 'logo', required: true, price: '2', types: ['application/pdf'], largest: 1),
        ]))->id;
        $path = (string) tempnam(sys_get_temp_dir(), 'logo');
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");
        $sent = $this->shop->uploads->send(new SendUpload(30, "f{$logo}_logo", $path, 'C:\\fakepath\\logo.pdf'));
        self::assertFileDoesNotExist($path, 'the upload is moved into the private store');

        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(30, personalisation: ["f{$logo}_logo" => $sent->token]))->newToken);
        $line = $this->shop->carts->cart($owner)->cart->lines[0];
        self::assertSame(['7', [['label' => 'Logo', 'answer' => 'logo.pdf', 'price' => '2']]], [$line->unitPrice, $line->personalisation]);
        $upload = array_values($this->shop->uploadRecords->uploads)[0];
        self::assertNotNull($upload->heldAt(), 'kept as long as the cart');

        $this->shop->clock->modify('+3 days');
        self::assertSame(0, $this->shop->uploads->forgetUntaken(), 'a cart still holds it');
        $this->shop->clock->modify('+5 days');
        self::assertSame(1, $this->shop->uploads->forgetUntaken());
        self::assertSame(CartLineView::CHANGED, $this->shop->carts->cart($owner)->cart->lines[0]->problem, 'the file is gone');
    }

    public function testEachSenderSendsSoMuchADayAndACartHoldsTenFiles(): void
    {
        $logo = $this->shop->forms->create(new SaveForm('Print', scope: 'products', productIds: [30], fields: [
            new SaveFormField('Logo', 'file', 'logo', types: ['application/pdf'], largest: 1),
        ]))->id;
        $send = function (string $address) use ($logo): string {
            $path = (string) tempnam(sys_get_temp_dir(), 'logo');
            file_put_contents($path, "%PDF-1.4\n%%EOF\n");

            return $this->shop->uploads->send(new SendUpload(30, "f{$logo}_logo", $path, 'logo.pdf', $address))->token;
        };
        $tokens = [$send('2001:db8:1:2::5')];
        $first = array_values($this->shop->uploadRecords->uploads)[0];
        new \ReflectionProperty($first, 'size')->setValue($first, FormUploads::MAX_SENT_A_DAY - 10);
        foreach (['2001:db8:1:2::5', '2001:db8:1:2:ffff::1'] as $same) {
            try {
                $send($same);
                self::fail('past the sender\'s day');
            } catch (InvalidFields $e) {
                self::assertSame('That is as many files as one visitor may send in a day. Try again tomorrow.', $e->messages()[0]['message'], 'an IPv6 network counts as one sender');
            }
        }
        new \ReflectionProperty($first, 'size')->setValue($first, 10);
        for ($i = 0; $i < FormUploads::MAX_HELD; ++$i) {
            $tokens[] = $send('203.0.113.' . $i);
        }

        $owner = new CartOwner(null);
        foreach (\array_slice($tokens, 0, FormUploads::MAX_HELD) as $token) {
            $owner = new CartOwner($this->shop->carts->add($owner, new AddToCart(30, personalisation: ["f{$logo}_logo" => $token]))->newToken ?? $owner->token);
        }
        self::assertSame(\strlen("%PDF-1.4\n%%EOF\n"), $this->shop->uploadRecords->untakenBytes(), 'only the file no cart took takes the room of new ones');
        $this->expectExceptionObject(new CartRuleViolation('A cart holds up to 10 files sent with its items.', 'orillacart_cart_files'));
        $this->shop->carts->add($owner, new AddToCart(30, personalisation: ["f{$logo}_logo" => $tokens[FormUploads::MAX_HELD]]));
    }

    /**
     * @param array<string, mixed> $answers by key, for the engraving form
     */
    private function add(array $answers, ?CartOwner $owner = null): CartOwner
    {
        $sent = [];
        foreach ($answers as $key => $answer) {
            $sent["f{$this->form}_{$key}"] = $answer;
        }
        $result = $this->shop->carts->add($owner ?? new CartOwner(null), new AddToCart(20, personalisation: $sent));

        return new CartOwner($result->newToken ?? $owner?->token);
    }

    private function place(CartOwner $owner): int
    {
        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'))->number;
    }

    /**
     * @param list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}> $answers
     *
     * @return list<array{id: string, label: string, answer: string, value: string, price: ?string, upload_id: ?int}>
     */
    private static function sorted(array $answers): array
    {
        usort($answers, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);

        return $answers;
    }
}
