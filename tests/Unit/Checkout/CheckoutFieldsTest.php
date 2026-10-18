<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\CheckoutDetails;
use OrillaCart\Checkout\Application\Dto\CheckoutFieldView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Extension\Checkout\CheckoutField;
use OrillaCart\Extension\Checkout\FieldCheck;
use OrillaCart\Extension\Checkout\FieldContext;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeOrderContact;
use OrillaCart\Ordering\Application\Dto\OrderFieldView;
use OrillaCart\Ordering\Application\OrderSummaries;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shared\Domain\InvalidFields;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

final class CheckoutFieldsTest extends TestCase
{
    private TestShop $shop;
    private AddressData $address;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $this->address = new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000');
        $vat = new readonly class implements FieldCheck {
            public function check(string $answer, FieldContext $context): ?string
            {
                return str_starts_with($answer, $context->billingCountry) ? null : 'A VAT number starts with the country code.';
            }
        };
        $this->shop->extensions->fields = [
            new CheckoutField('vat_number', 'VAT number', check: $vat),
            new CheckoutField('door_code', 'Door code', section: CheckoutField::SHIPPING),
            new CheckoutField('wrap', 'Gift wrap', CheckoutField::CHOICE, CheckoutField::ORDER, choices: ['paper' => 'Paper', 'box' => 'A box']),
            new CheckoutField('terms', 'I accept the terms', CheckoutField::CHECKBOX, CheckoutField::ORDER, required: true),
        ];
    }

    public function testCheckoutAsksWhatTheOrderNeeds(): void
    {
        $summary = $this->shop->checkout->summary($this->cart(), new CheckoutDetails(billingCountry: 'BG'));

        self::assertSame(['vat_number', 'wrap', 'terms'], array_map(static fn(CheckoutFieldView $field): string => $field->id, $summary->fields), 'nothing ships, so no door code');
        self::assertSame([['value' => 'paper', 'label' => 'Paper'], ['value' => 'box', 'label' => 'A box']], $summary->fields[1]->choices);
    }

    public function testEachRefusedAnswerIsReportedNextToItsField(): void
    {
        try {
            $this->place(['vat_number' => 'DE123', 'wrap' => 'bag']);
            self::fail('refused answers');
        } catch (InvalidFields $e) {
            self::assertSame([
                ['field' => 'fields.vat_number', 'message' => 'A VAT number starts with the country code.'],
                ['field' => 'fields.wrap', 'message' => 'This answer is not one of the choices.'],
                ['field' => 'fields.terms', 'message' => 'Tick this to order.'],
            ], $e->violations);
        }
        self::assertSame([], $this->shop->orderRecords->orders, 'no order');
    }

    public function testTheOrderKeepsTheAnswersWithTheirLabelsAndTheShopCorrectsThem(): void
    {
        $number = $this->place(['vat_number' => ' BG175074752 ', 'wrap' => 'box', 'terms' => true, 'door_code' => '1234', 'other' => 'x']);

        $order = $this->shop->orders->order($number);
        self::assertEquals([
            new OrderFieldView('vat_number', 'VAT number', 'BG175074752'),
            new OrderFieldView('wrap', 'Gift wrap', 'A box'),
            new OrderFieldView('terms', 'I accept the terms', 'Yes'),
        ], $order->fields, 'only what was asked, a choice by its label');

        $this->shop->extensions->fields = [];
        $changed = $this->shop->orders->changeContact($number, new ChangeOrderContact($order->email, $order->billing, fields: ['vat_number' => 'BG999', 'new' => 'x']), null);
        self::assertSame(['BG999', 'A box'], [$changed->fields[0]->answer, $changed->fields[1]->answer], 'answers are corrected after the field is gone');
        self::assertCount(3, $changed->fields);

        $summary = new OrderSummaries($this->shop->orders)->find($number);
        self::assertNotNull($summary);
        self::assertSame(['vat_number' => 'BG999', 'wrap' => 'A box', 'terms' => 'Yes'], $summary->fields);
        self::assertSame([$number, 'on_hold', 'ada@example.com', 'Sofia', '8', 'E-book'], [$summary->number, $summary->status, $summary->email, $summary->billing->city, $summary->total, $summary->lines[0]->name]);
        self::assertNull(new OrderSummaries($this->shop->orders)->find(999));
    }

    /**
     * @param array<string, string|bool> $fields
     */
    private function place(array $fields): int
    {
        return $this->shop->checkout->placeOrder($this->cart(), new PlaceOrder('ada@example.com', $this->address, payment: 'bank_transfer', fields: $fields))->number;
    }

    private function cart(): CartOwner
    {
        return new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);
    }
}
