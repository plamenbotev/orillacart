<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Ordering;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Catalog\Application\Dto\UploadDownload;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Domain\DownloadGrant;
use OrillaCart\Ordering\Domain\DownloadUnavailable;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use OrillaCart\Tests\Fixtures\TemporaryPrivateFiles;
use PHPUnit\Framework\TestCase;

final class DownloadResumesTest extends TestCase
{
    private const int SIZE = 5 * 1048576;
    private const string CLIENT = '203.0.113.5';

    private TestShop $shop;
    private string $token;

    protected function setUp(): void
    {
        $this->shop = new TestShop();
        $this->shop->content->add(20, 'Guide');
        $this->shop->products->save(20, new SaveProduct('8', 'virtual', downloadLimit: 1, downloadExpiryDays: 30));
        $this->shop->downloadFiles->add(20, new UploadDownload(TemporaryPrivateFiles::upload(str_repeat('x', self::SIZE)), 'guide.pdf'));
        $this->shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $result = $this->shop->carts->add(new CartOwner(null), new AddToCart(20));
        $order = $this->shop->checkout->placeOrder(new CartOwner($result->newToken, null), new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), payment: 'bank_transfer'));
        $this->shop->orders->markPaidByShop($order->number, '', 1);
        $this->token = substr($this->shop->orders->order($order->number)->downloads[0]->url, -64);
    }

    public function testShortRangesCannotWalkHowFarTheDownloadWentBackToTheStart(): void
    {
        $this->shop->downloads->open($this->token, '', self::CLIENT);
        $byte = self::SIZE - DownloadGrant::overlap(self::SIZE);
        // One byte from a little before the end: a resume, which leaves the download sent to its end.
        $this->shop->downloads->open($this->token, "bytes={$byte}-{$byte}", self::CLIENT);

        $this->expectExceptionMessage('There are no downloads left for this file.');
        $further = $byte + 1 - DownloadGrant::overlap(self::SIZE);
        $this->shop->downloads->open($this->token, "bytes={$further}-{$further}", self::CLIENT);
    }

    public function testAResumeThatBreaksOffGivesBackOnlyItsOwnPart(): void
    {
        $this->shop->downloads->open($this->token, '', self::CLIENT);
        $from = self::SIZE - DownloadGrant::overlap(self::SIZE);
        $file = $this->shop->downloads->open($this->token, "bytes={$from}-", self::CLIENT);
        // Broke off before a byte of it went out: what the counted download sent stays sent.
        $this->shop->downloads->sentOnly($this->token, $from, $file->sentBefore);

        $this->expectExceptionMessage('There are no downloads left for this file.');
        $further = $from - DownloadGrant::overlap(self::SIZE);
        $this->shop->downloads->open($this->token, "bytes={$further}-", self::CLIENT);
    }

    public function testWhenTheWebServerSendsTheFileARangeFromTheSameAddressSoonAfterIsAResume(): void
    {
        $this->shop->downloads->open($this->token, 'bytes=0-', self::CLIENT, tracked: false);
        // A segmented downloader asks for the parts while the first request is still going.
        foreach ([1, 2, 3, 4] as $segment) {
            $from = $segment * 1048576;
            $this->shop->downloads->open($this->token, \sprintf('bytes=%d-%d', $from, $from + 1048575), self::CLIENT, tracked: false);
        }

        try {
            $this->shop->downloads->open($this->token, 'bytes=1048576-', '198.51.100.7', tracked: false);
            self::fail('another address resumed a download it was not given');
        } catch (DownloadUnavailable $e) {
            self::assertSame('There are no downloads left for this file.', $e->getMessage());
        }
        $this->expectExceptionMessage('There are no downloads left for this file.');
        $this->shop->downloads->open($this->token, '', self::CLIENT, tracked: false);
    }

    public function testARangeThatCannotBeServedDoesNotCountADownload(): void
    {
        $this->shop->downloads->open($this->token, \sprintf('bytes=%d-', self::SIZE + 10), self::CLIENT);

        $file = $this->shop->downloads->open($this->token, '', self::CLIENT);
        self::assertSame(self::SIZE, filesize($file->path));
    }
}
