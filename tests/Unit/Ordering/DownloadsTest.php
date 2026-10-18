<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Ordering;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\Dto\UploadDownload;
use OrillaCart\Checkout\Application\Dto\PlacedOrderView;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Customers\Domain\CustomerNotFound;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeDownloadAllowance;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Ordering\Domain\DownloadUnavailable;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Payment\Application\Dto\MakeRefund;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\TemporaryPrivateFiles;
use PHPUnit\Framework\TestCase;

final class DownloadsTest extends TestCase
{
    private TestShop $shop;
    private int $guide;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'Guide');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual', downloadLimit: 2, downloadExpiryDays: 30));
        $this->guide = $this->shop->downloadFiles->add(20, new UploadDownload(TemporaryPrivateFiles::upload('%PDF guide'), 'guide.pdf'))->id;
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
    }

    public function testAPaidOrderGetsItsDownloadsWithTheProductsLimitAndExpiry(): void
    {
        $order = $this->place();
        $unpaid = $this->shop->orders->byKey($order->key);
        self::assertSame([], $unpaid->downloads, 'nothing before the money arrives');

        $this->shop->orders->changeStatus($order->number, new ChangeOrderStatus('processing'), 1);

        $downloads = $this->shop->orders->byKey($order->key)->downloads;
        self::assertCount(1, $downloads);
        self::assertSame(['Guide', 'guide.pdf', 2, '2026-11-16T12:00:00+00:00'], [$downloads[0]->product, $downloads[0]->name, $downloads[0]->remaining, $downloads[0]->expiresAt]);
        self::assertMatchesRegularExpression('#^https://shop\.test/\?orillacart_download=[a-f0-9]{64}$#', $downloads[0]->url);

        $file = $this->shop->downloads->open($this->token($downloads[0]->url));
        self::assertSame(['guide.pdf', '%PDF guide'], [$file->filename, file_get_contents($file->path)]);
        $this->shop->downloads->open($this->token($downloads[0]->url));
        $this->expectExceptionMessage('There are no downloads left for this file.');
        $this->shop->downloads->open($this->token($downloads[0]->url));
    }

    public function testLinksStopWorkingWhenTheyExpireOrTheOrderIsRefunded(): void
    {
        $expiring = $this->paid();
        $refunded = $this->paid();
        $this->shop->refunds->refund($refunded->number, new MakeRefund($this->shop->orders->order($refunded->number)->total), 1);
        self::assertSame([], $this->shop->orders->byKey($refunded->key)->downloads);

        $this->shop->clock->modify('+31 days');
        $this->expectException(DownloadUnavailable::class);
        $this->expectExceptionMessage('The download link has expired.');
        $this->shop->downloads->open($this->token($this->shop->orders->byKey($expiring->key)->downloads[0]->url));
    }

    public function testAForgedOrUnknownLinkGivesNothing(): void
    {
        $this->paid();
        foreach (['../../wp-config.php', str_repeat('a', 64)] as $token) {
            try {
                $this->shop->downloads->open($token);
                self::fail($token);
            } catch (DownloadUnavailable $e) {
                self::assertSame('This download link does not work.', $e->getMessage());
            }
        }
    }

    public function testARemovedFileStaysForThoseWhoBoughtItAndGoesWhenNobodyDid(): void
    {
        $order = $this->paid();
        $unsold = $this->shop->downloadFiles->add(20, new UploadDownload(TemporaryPrivateFiles::upload('extra'), 'extra.zip'));

        $this->shop->downloadFiles->remove(20, $this->guide);
        $this->shop->downloadFiles->remove(20, $unsold->id);

        self::assertCount(1, $this->shop->files->deleted, 'only the file nobody bought is deleted');
        self::assertSame('%PDF guide', file_get_contents($this->shop->downloads->open($this->token($this->shop->orders->byKey($order->key)->downloads[0]->url))->path));
    }

    public function testACustomerSeesTheDownloadsOfTheirPaidOrders(): void
    {
        $customer = $this->shop->accounts->add('ada@example.com');
        $order = $this->paid(new CartOwner(null, $customer));
        $this->place(new CartOwner(null, $customer));

        $downloads = $this->shop->downloads->ofCustomer($customer);
        self::assertSame([[$order->number, 'guide.pdf']], array_map(static fn($download): array => [$download->orderNumber, $download->name], $downloads));
    }

    private function token(string $url): string
    {
        return substr($url, -64);
    }

    public function testTheShopGivesMoreDownloadsOrTime(): void
    {
        $order = $this->paid();
        $grant = $this->shop->orders->byKey($order->key)->downloads[0];
        $url = $grant->url;
        $this->shop->downloads->open($this->token($url));
        $this->shop->downloads->open($this->token($url));

        $changed = $this->shop->orders->changeDownloadAllowance($order->number, $grant->id, new ChangeDownloadAllowance(1, '2027-01-31'), 1);

        self::assertSame([1, '2027-01-31T23:59:59+00:00'], [$changed->downloads[0]->remaining, $changed->downloads[0]->expiresAt]);
        self::assertContains('Downloads of guide.pdf: 1 left, until 2027-01-31.', array_column($changed->notes, 'text'));
        $this->shop->downloads->open($this->token($url));

        $this->expectException(OrderNotFound::class);
        $this->shop->orders->changeDownloadAllowance($order->number, 999, new ChangeDownloadAllowance(null, null), 1);
    }

    public function testAnOrderMovesToAnotherAccountOrBecomesAGuestOrder(): void
    {
        $customer = $this->shop->accounts->add('grace@example.com');
        $order = $this->paid();

        $moved = $this->shop->customers->assignOrder($order->number, $customer, 1);
        self::assertSame($customer, $moved->customerId);
        self::assertCount(1, $this->shop->downloads->ofCustomer($customer), 'the files come with the order');

        self::assertNull($this->shop->customers->assignOrder($order->number, null, 1)->customerId);
        $this->expectException(CustomerNotFound::class);
        $this->shop->customers->assignOrder($order->number, 999, 1);
    }

    private function paid(CartOwner $owner = new CartOwner(null)): PlacedOrderView
    {
        $order = $this->place($owner);
        $this->shop->orders->changeStatus($order->number, new ChangeOrderStatus('processing'), 1);

        return $order;
    }

    private function place(CartOwner $owner = new CartOwner(null)): PlacedOrderView
    {
        $result = $this->shop->carts->add($owner, new AddToCart(20));

        return $this->shop->checkout->placeOrder(new CartOwner($result->newToken ?? $owner->token, $owner->customerId), new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'));
    }
}
