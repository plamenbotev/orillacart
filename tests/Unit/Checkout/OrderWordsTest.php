<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Checkout;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveAttribute;
use OrillaCart\Catalog\Application\Dto\SaveOption;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\EditedItem;
use OrillaCart\Checkout\Application\Dto\EditOrderItems;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Forms\Application\Dto\SaveFieldChoice;
use OrillaCart\Forms\Application\Dto\SaveForm;
use OrillaCart\Forms\Application\Dto\SaveFormField;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Shared\I18n\Language;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/**
 * Design 51: an order keeps the customer's words, which their emails and invoices read, and the shop's beside them,
 * which the shop reads: its items, their options and answers, the way it ships and the way it is paid.
 */
final class OrderWordsTest extends TestCase
{
    private TestShop $shop;
    private int $form;

    protected function setUp(): void
    {
        $this->shop = new TestShop('2026-10-29 12:00:00 UTC');
        $this->shop->contentLanguages->languages = [new Language('en', 'en_US', 'English'), new Language('de', 'de_DE', 'Deutsch')];
        $this->shop->content->add(20, 'Ring');
        $this->shop->content->add(21, 'Ehering');
        $this->shop->contentLanguages->translate(['en' => 20, 'de' => 21]);
        $this->shop->products->save(20, new SaveProduct('100', attributes: [new SaveAttribute('Metal', [new SaveOption('Gold', translations: ['de_DE' => 'Gold']), new SaveOption('Silver', translations: ['de_DE' => 'Silber'])], translations: ['de_DE' => 'Metall'])]));
        $this->shop->carriers->create(new SaveCarrier('Courier', rates: [new SaveShippingRate('Standard', '5', translations: ['de_DE' => 'Normalversand'])], translations: ['de_DE' => 'Kurier']));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true, translations: ['name' => ['de_DE' => 'Überweisung']]));
        $this->form = $this->shop->forms->create(new SaveForm('Engraving', fields: [
            new SaveFormField('Engrave it', 'choice', 'engrave', choices: [new SaveFieldChoice('Yes please', 'yes', translations: ['de_DE' => 'Ja, bitte']), new SaveFieldChoice('No', 'no')], translations: ['label' => ['de_DE' => 'Gravieren']]),
        ]))->id;
    }

    public function testTheShopReadsItsOwnWordsAndTheCustomerTheirs(): void
    {
        $number = $this->placeInGerman();

        $shop = $this->shop->orders->order($number);
        self::assertSame(['Ring', [['attribute' => 'Metal', 'option' => 'Silver']], 'Engrave it', 'Yes please'], self::item($shop));
        self::assertSame(['Courier – Standard', 'Bank transfer'], [$shop->shipping, $shop->paymentName]);

        $customer = $this->shop->orders->inCustomerWords($number);
        self::assertSame(['Ehering', [['attribute' => 'Metall', 'option' => 'Silber']], 'Gravieren', 'Ja, bitte'], self::item($customer));
        self::assertSame(['Kurier – Normalversand', 'Überweisung', 'de_DE'], [$customer->shipping, $customer->paymentName, $customer->locale]);

        // The customer's confirmation reads theirs, the shop's own email its own.
        $mails = $this->shop->mail->emails;
        $toCustomer = array_values(array_filter($mails, static fn($mail): bool => $mail->to === 'ada@example.com'));
        $toShop = array_values(array_filter($mails, static fn($mail): bool => $mail->to !== 'ada@example.com'));
        self::assertNotSame([], $toCustomer);
        self::assertNotSame([], $toShop);
        self::assertStringContainsString('Ehering', $toCustomer[0]->html);
        self::assertStringNotContainsString('Ehering', $toShop[0]->html);
        self::assertStringContainsString('Ring', $toShop[0]->html);
    }

    public function testAnEditByTheShopKeepsTheCustomersWordsAndItsOwn(): void
    {
        $number = $this->placeInGerman();
        // The admin works in the site's language.
        $this->shop->languages->locale = 'en_US';
        $this->shop->contentLanguages->current = null;

        $silver = $this->shop->products->product(20)->attributes[0]->options[1]->id;
        $this->shop->editing->edit($number, new EditOrderItems([new EditedItem(20, 2, [$silver], line: 0)], 'rate:1'), 7);

        self::assertSame(['Ehering', [['attribute' => 'Metall', 'option' => 'Silber']], 'Gravieren', 'Ja, bitte'], self::item($this->shop->orders->inCustomerWords($number)));
        self::assertSame(['Ring', [['attribute' => 'Metal', 'option' => 'Silver']], 'Engrave it', 'Yes please'], self::item($this->shop->orders->order($number)));
    }

    private function placeInGerman(): int
    {
        // The shopper's request is in German: the page's language, which its calls carry.
        $this->shop->languages->locale = 'de_DE';
        $silver = $this->shop->products->product(20)->attributes[0]->options[1]->id;
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20, [$silver], personalisation: ["f{$this->form}_engrave" => 'yes']))->newToken);

        return $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: 'rate:1', payment: 'bank_transfer'))->number;
    }

    /**
     * @return array{string, list<array{attribute: string, option: string}>, string, string}
     */
    private static function item(OrderView $order): array
    {
        $line = $order->lines[0];

        return [$line->name, $line->options, $line->personalisation[0]['label'] ?? '', $line->personalisation[0]['answer'] ?? ''];
    }
}
