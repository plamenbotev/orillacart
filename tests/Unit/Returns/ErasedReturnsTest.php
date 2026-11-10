<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Returns;

use OrillaCart\Cart\Application\Dto\AddToCart;
use OrillaCart\Cart\Application\Dto\CartOwner;
use OrillaCart\Catalog\Application\Dto\SaveProduct;
use OrillaCart\Checkout\Application\Dto\PlaceOrder;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Ordering\Domain\Event\OrderAnonymised;
use OrillaCart\Payment\Application\Dto\SavePaymentMethod;
use OrillaCart\Returns\Application\Dto\AnswerReturn;
use OrillaCart\Returns\Application\Dto\RequestReturn;
use OrillaCart\Returns\Application\Dto\ReturnItem;
use OrillaCart\Returns\Application\ReturnsPersonalData;
use OrillaCart\Returns\Infrastructure\Events\ForgetErasedReturns;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shipping\Application\Dto\SaveCarrier;
use OrillaCart\Shipping\Application\Dto\SaveShippingRate;
use OrillaCart\Tests\Fixtures\Checkout\TestShop;
use PHPUnit\Framework\TestCase;

/** What a customer wrote asking to send items back is theirs: in their export, and erased with them. */
final class ErasedReturnsTest extends TestCase
{
    public function testTheCommentAndTheAnswerAreExportedAndErased(): void
    {
        $shop = new TestShop();
        $shop->settings->save(new StoreSettings(new Currency('EUR'), 2, WeightUnit::Kilogram, DimensionUnit::Centimetre, 'BG', returnDays: 30));
        $shop->content->add(10, 'Lamp');
        $shop->products->save(10, new SaveProduct('30', weight: '2', stockMode: 'always_available'));
        $shop->paymentMethods->update('bank_transfer', new SavePaymentMethod('Bank transfer', true));
        $rate = $shop->carriers->create(new SaveCarrier('Post', rates: [new SaveShippingRate('Anywhere', '5')]))->rates[0]->id;
        $owner = new CartOwner($shop->carts->add(new CartOwner(null), new AddToCart(10, quantity: 2))->newToken);
        $order = $shop->checkout->placeOrder($owner, new PlaceOrder('ada@example.com', new AddressData('Ada', 'Lovelace', '1 Vitosha Blvd', 'Sofia', '1000', 'BG', '+359 2 000 0000'), shippingOption: "rate:{$rate}", payment: 'bank_transfer'))->number;
        $shop->orders->markPaid($order, null);
        $shop->orders->changeStatus($order, new ChangeOrderStatus('shipped'), 7);
        $return = $shop->returns->request($shop->receipts->issue($order), new RequestReturn([new ReturnItem(0, 1)], 'damaged', 'The shade is cracked. Call me on +359 2 000 0000.'));
        $shop->returns->approve($return->id, new AnswerReturn('Send it to 1 Shop St, Sofia, Ada.'), 7);
        $data = new ReturnsPersonalData($shop->returnRecords, $shop->orders);

        $items = $data->personalData('ada@example.com', null);
        self::assertSame(['orillacart-return-' . $return->id], array_column($items, 'id'));
        $rows = array_column($items[0]['data'], 'value', 'name');
        self::assertSame(['#' . $order, 'damaged', 'The shade is cracked. Call me on +359 2 000 0000.', 'Send it to 1 Shop St, Sofia, Ada.'], [$rows['Order'], $rows['Reason'], $rows['Comment'], $rows['The shop\'s answer']]);
        self::assertSame([], $data->personalData('grace@example.com', null));

        $shop->orders->anonymise($order, null);
        new ForgetErasedReturns($shop->returnRecords)->anonymised(new OrderAnonymised($order, 'ada@example.com'));

        $kept = $shop->returnRecords->forOrder($order)[0];
        self::assertSame(['', '', [0 => 1]], [$kept->comment(), $kept->answer(), $kept->items()], 'the items stay for the books');
    }
}
