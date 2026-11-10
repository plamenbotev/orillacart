<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

/**
 * A customer's store credit as the customer's card reads it: nothing, with no id, until the shop adds some.
 */
final class StoreCreditRestTest extends RestTestCase
{
    public function testACustomerWithoutStoreCreditHasAnEmptyUnsavedCard(): void
    {
        $customer = $this->actAs('orillacart_customer');
        $this->actAs('administrator');

        $response = $this->request('GET', '/customers/' . $customer . '/store-credit');

        self::assertSame(200, $response->get_status());
        $credit = $this->data($response);
        self::assertIsArray($credit['card']);
        self::assertNull($credit['card']['id'], 'not saved until some is added');
        self::assertSame('0', $credit['card']['balance']);
        self::assertSame([], $credit['entries']);
    }
}
