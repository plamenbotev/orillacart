<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Notifications;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Customers\Application\Dto\CustomerRecord;
use OrillaCart\Notifications\Application\ExemptionEmails;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Tax\Application\Dto\TaxExemptionView;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\Customers\RecordingAccountMail;
use PHPUnit\Framework\TestCase;

/**
 * The customer hears of their exemption certificate in their own language, never the shop manager's.
 */
final class ExemptionEmailsTest extends TestCase
{
    private TestShop $shop;
    private ExemptionEmails $emails;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->emails = new ExemptionEmails($this->shop->writer, $this->shop->mail, $this->shop->emailSettings, new RecordingAccountMail(), $this->shop->logger, $this->shop->accounts, $this->shop->orders);
        $this->shop->content->add(20, 'E-book');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
    }

    public function testTheReviewGoesInTheLanguageOfTheCustomersLatestOrder(): void
    {
        $ada = $this->shop->accounts->add('ada@example.com');
        $this->order('fr_FR');
        $this->order('de_DE');
        $this->shop->languages->locale = 'bg_BG';
        $this->shop->languages->switched = [];

        $this->emails->reviewed(self::exemption($ada), 'ada@example.com', 'Ada');
        $this->emails->expiring(self::exemption($ada), 'ada@example.com', 'Ada');

        self::assertSame(['exemption_reviewed', 'exemption_expiring'], \array_slice(array_map(static fn(\OrillaCart\Notifications\Application\Dto\Email $email): ?string => $email->kind, $this->shop->mail->emails), -2));
        self::assertSame(['de_DE', 'de_DE'], $this->shop->languages->switched);
    }

    public function testWithoutAnOrderItGoesInTheLanguageTheirProfileNamesOrTheSites(): void
    {
        $grace = $this->shop->accounts->add('grace@example.com', 'Grace');
        $record = $this->shop->accounts->accounts[$grace];
        $this->shop->accounts->accounts[$grace] = new CustomerRecord($grace, $record->email, $record->firstName, $record->lastName, $record->registeredAt, locale: 'it_IT');
        $alan = $this->shop->accounts->add('alan@example.com', 'Alan');
        $this->shop->languages->locale = 'bg_BG';

        $this->emails->reviewed(self::exemption($grace), 'grace@example.com', 'Grace');
        $this->emails->reviewed(self::exemption($alan), 'alan@example.com', 'Alan');

        self::assertSame(['it_IT', null], $this->shop->languages->switched);
    }

    private function order(string $locale): void
    {
        $this->shop->languages->locale = $locale;
        $owner = new CartOwner($this->shop->carts->add(new CartOwner(null), new AddToCart(20))->newToken);
        $this->shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'));
    }

    private static function exemption(int $customerId): TaxExemptionView
    {
        return new TaxExemptionView(1, $customerId, 'reseller', 'RS-1', [], '2027-10-30', '', '2026-10-17T12:00:00+00:00', 'approved', reviewedAt: '2026-10-18T12:00:00+00:00');
    }
}
