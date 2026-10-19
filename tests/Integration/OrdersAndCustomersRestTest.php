<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use OrillaCart\Cart\Http\CartCookie;
use OrillaCart\Catalog\Infrastructure\WordPress\Content\ProductPostType;
use OrillaCart\Customers\Infrastructure\WordPress\WordPressAccounts;
use OrillaCart\Ordering\Application\Downloads;
use OrillaCart\Tests\Fixtures\Extension\TestAddOn;

/**
 * The shop works its orders and customers through the REST routes: statuses, notes and emails, editing an order
 * on hold, customer accounts made at checkout, and downloads of a paid order.
 */
final class OrdersAndCustomersRestTest extends RestTestCase
{
    /** @var list<int> */
    private array $posts = [];
    /** @var list<int> */
    private array $users = [];
    private int $lamp;
    private int $guide;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clean();
        unset($_COOKIE[CartCookie::NAME]);
        $this->actAs('administrator');
        $this->lamp = $this->product('Lamp', ['price' => '30', 'type' => 'virtual']);
        $this->guide = $this->product('Guide', ['price' => '8', 'type' => 'virtual', 'download_limit' => 2]);
        $stockroom = $this->id($this->request('POST', '/stockrooms', ['name' => 'Sofia'], nonce: true));
        $this->request('PUT', "/stock/product/{$this->lamp}", ['levels' => [['stockroom_id' => $stockroom, 'quantity' => 3]]], nonce: true);
        $this->request('PUT', '/payment-methods/bank_transfer', ['name' => 'Bank transfer', 'active' => true], nonce: true);
        wp_set_current_user(0);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[CartCookie::NAME]);
        foreach ($this->posts as $post) {
            wp_delete_post($post, true);
        }
        foreach ($this->users as $user) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($user);
        }
        $this->clean();
        parent::tearDown();
    }

    public function testOnlyShopManagersWorkOrdersAndOnlyCustomersSeeTheirAccount(): void
    {
        $shop = [['GET', '/orders'], ['GET', '/orders/1'], ['PUT', '/orders/1/status'], ['PUT', '/orders/1/contact'], ['POST', '/orders/1/notes'],
            ['PUT', '/orders/1/items'], ['POST', '/orders/1/emails'], ['DELETE', '/orders/1'], ['GET', '/customers'], ['GET', '/customers/1'],
            ['PUT', '/customers/1/addresses'], ['GET', '/customer-settings'], ['PUT', '/customer-settings'], ['GET', '/email-settings'],
            ['PUT', '/email-settings'], ['POST', '/products/1/downloads'], ['DELETE', '/products/1/downloads/1'], ['GET', '/download-storage'],
            ['PUT', '/orders/1/customer'], ['GET', '/orders/1/shipping-options'], ['PUT', '/orders/1/downloads/1'], ['POST', '/orders'], ['POST', '/orders/quote'],
            ['GET', '/orders/1/shipments'], ['POST', '/orders/1/shipments'], ['GET', '/shipments/1/label'], ['POST', '/shipments/1/cancel'], ['GET', '/shipping-methods'], ['GET', '/orders/1/pickup-points'],
            ['GET', '/search-filters'], ['POST', '/search-filters'], ['PUT', '/search-filters/1'], ['DELETE', '/search-filters/1'], ['GET', '/search-filters/attributes'],
            ['GET', '/search-engine'], ['PUT', '/search-engine'], ['POST', '/search-engine/rebuild'],
            ['GET', '/orders/1/refunds'], ['POST', '/orders/1/refunds'], ['POST', '/refunds/1/retry'], ['POST', '/refunds/1/give-up'],
            ['GET', '/price-rules'], ['POST', '/price-rules'], ['PUT', '/price-rules/1'], ['DELETE', '/price-rules/1'],
            ['GET', '/customer-groups'], ['POST', '/customer-groups'], ['PUT', '/customer-groups/1'], ['DELETE', '/customer-groups/1'], ['PUT', '/customers/1/groups'],
            ['GET', '/coupons'], ['POST', '/coupons'], ['GET', '/coupons/1'], ['PUT', '/coupons/1'], ['DELETE', '/coupons/1'],
            ['GET', '/jobs'], ['GET', '/jobs/failed'], ['POST', '/jobs/failed/retry'], ['POST', '/jobs/failed/delete'], ['POST', '/jobs/workers/restart'],
            ['GET', '/orders/1/actions'], ['POST', '/orders/1/actions/test_export']];
        $account = [['GET', '/account'], ['PUT', '/account/addresses'], ['GET', '/account/orders'], ['GET', '/account/orders/1'], ['GET', '/account/downloads']];
        foreach ([...$shop, ...$account] as [$method, $route]) {
            self::assertSame(401, $this->request($method, $route)->get_status(), "visitor: {$method} {$route}");
        }
        $this->actAs('subscriber');
        foreach ($shop as [$method, $route]) {
            self::assertSame(403, $this->request($method, $route, nonce: true)->get_status(), "subscriber: {$method} {$route}");
        }
        self::assertSame(200, $this->request('GET', '/account/orders')->get_status());
    }

    public function testTheShopMovesAnOrderAlongAndTheCustomerHearsOfIt(): void
    {
        $order = $this->order([[$this->lamp, 1]]);
        $this->actAs('administrator');

        $list = $this->data($this->request('GET', '/orders?status=on_hold&search=ada'));
        self::assertSame([[$order['number']], 1], [array_column($this->rows($list['orders']), 'number'), $list['total']]);

        $this->sentMail();
        $this->mails = [];
        $changed = $this->request('POST', "/orders/{$order['number']}/paid", ['note' => 'Paid at the bank.'], nonce: true);
        self::assertSame(200, $changed->get_status(), (string) wp_json_encode($changed->get_data()));
        self::assertSame(['shipped', 'completed'], $this->data($changed)['next_statuses']);
        $this->request('POST', "/orders/{$order['number']}/notes", ['text' => 'It leaves tomorrow.', 'for_customer' => true], nonce: true);
        $sent = [];
        foreach ($this->sentMail() as $mail) {
            $sent[] = $mail['to'] . ': ' . preg_replace('/^\[[^\]]+\] /', '', $mail['subject']);
        }
        self::assertSame(["ada@example.com: Your order #{$order['number']} is confirmed", "ada@example.com: A note about your order #{$order['number']}"], $sent);

        $bad = $this->request('PUT', "/orders/{$order['number']}/status", ['status' => 'pending_payment'], nonce: true);
        self::assertSame(422, $bad->get_status());
        self::assertSame(422, $this->request('DELETE', "/orders/{$order['number']}", nonce: true)->get_status(), 'a paid order is cancelled or refunded first');

        $receipt = $this->data($this->request('GET', "/receipts/{$order['key']}"));
        self::assertSame(['It leaves tomorrow.'], array_column($this->rows($receipt['notes']), 'text'), 'the customer sees only their notes');
    }

    public function testACustomerGroupGetsItsPriceInTheCartAndGuestsPayTheRegularOne(): void
    {
        $this->actAs('administrator');
        $group = $this->request('POST', '/customer-groups', ['name' => 'Wholesale'], nonce: true);
        self::assertSame(201, $group->get_status(), (string) wp_json_encode($group->get_data()));
        $groupId = $this->data($group)['id'];
        self::assertIsInt($groupId);
        $rule = $this->request('POST', '/price-rules', ['name' => 'Trade', 'tiers' => [['min_quantity' => 1, 'kind' => 'percent', 'value' => '20']], 'group_ids' => [$groupId]], nonce: true);
        self::assertSame(201, $rule->get_status(), (string) wp_json_encode($rule->get_data()));
        self::assertSame(422, $this->request('POST', '/price-rules', ['name' => 'Empty', 'tiers' => []], nonce: true)->get_status(), 'a rule has a price');

        $customer = $this->actAs('customer');
        $this->actAs('administrator');
        $set = $this->request('PUT', "/customers/{$customer}/groups", ['group_ids' => [$groupId]], nonce: true);
        self::assertSame([$groupId], $this->data($set)['group_ids']);
        self::assertSame([$groupId], $this->data($this->request('GET', "/customers/{$customer}"))['group_ids']);

        wp_set_current_user(0);
        unset($_COOKIE[CartCookie::NAME]);
        self::assertSame('30', $this->data($this->request('POST', '/cart/items', ['product_id' => $this->lamp, 'quantity' => 1], nonce: true))['subtotal']);
        wp_set_current_user($customer);
        unset($_COOKIE[CartCookie::NAME]);
        self::assertSame('24', $this->data($this->request('POST', '/cart/items', ['product_id' => $this->lamp, 'quantity' => 1], nonce: true))['subtotal']);
        $this->actAs('administrator');
        self::assertSame(204, $this->request('DELETE', "/customer-groups/{$groupId}", nonce: true)->get_status());
    }

    public function testAPaidOrderGetsTheNextInvoiceNumberAndARefundACreditNote(): void
    {
        $this->actAs('administrator');
        $settings = $this->request('PUT', '/invoice-settings', ['legal_name' => 'Lamps Ltd', 'vat_number' => 'BG123', 'invoice_prefix' => 'T', 'credit_note_prefix' => 'TC', 'yearly' => false], nonce: true);
        self::assertSame(200, $settings->get_status(), (string) wp_json_encode($settings->get_data()));
        self::assertSame(422, $this->request('PUT', '/invoice-settings', ['invoice_prefix' => 'X', 'credit_note_prefix' => 'X'], nonce: true)->get_status(), 'one prefix for both');
        $first = $this->order([[$this->lamp, 1]]);
        $second = $this->order([[$this->lamp, 1]]);
        $this->actAs('administrator');

        $this->request('POST', "/orders/{$first['number']}/paid", [], nonce: true);
        $this->request('POST', "/orders/{$second['number']}/paid", [], nonce: true);
        $this->request('POST', "/orders/{$first['number']}/refunds", ['amount' => '5', 'outside' => true], nonce: true);

        $firstInvoices = $this->rows($this->data($this->request('GET', "/orders/{$first['number']}/invoices")));
        $secondInvoices = $this->rows($this->data($this->request('GET', "/orders/{$second['number']}/invoices")));
        [$invoice, $creditNote, $next, $url] = [$firstInvoices[0]['number'] ?? null, $firstInvoices[1]['number'] ?? null, $secondInvoices[0]['number'] ?? null, $firstInvoices[0]['url'] ?? null];
        self::assertIsString($invoice);
        self::assertIsString($creditNote);
        self::assertIsString($next);
        self::assertIsString($url);
        self::assertMatchesRegularExpression('/^T\d{5}$/', $invoice);
        self::assertSame((int) substr($invoice, 1) + 1, (int) substr($next, 1), 'the next number');
        self::assertMatchesRegularExpression('/^TC\d{5}$/', $creditNote);
        self::assertStringContainsString('orillacart_invoice=', $url);
        self::assertSame(422, $this->request('DELETE', "/orders/{$first['number']}", nonce: true)->get_status(), 'it stays for the books');
    }

    public function testWordPressPrivacyToolsExportAndEraseAPersonsOrders(): void
    {
        $first = $this->order([[$this->lamp, 1]]);
        $second = $this->order([[$this->lamp, 1]]);
        $this->actAs('administrator');
        $this->request('POST', "/orders/{$first['number']}/paid", [], nonce: true);

        $export = $this->privacyCallback('wp_privacy_personal_data_exporters');
        $exported = $export('ada@example.com', 1);
        self::assertIsArray($exported);
        self::assertTrue($exported['done'] ?? null);
        self::assertSame(["orillacart-order-{$first['number']}", "orillacart-order-{$second['number']}"], array_column($this->rows($exported['data'] ?? null), 'item_id'));

        $erase = $this->privacyCallback('wp_privacy_personal_data_erasers');
        $erased = $erase('ada@example.com', 1);
        self::assertIsArray($erased);
        self::assertSame([true, true, true], [$erased['items_removed'] ?? null, $erased['items_retained'] ?? null, $erased['done'] ?? null]);

        $order = $this->data($this->request('GET', "/orders/{$first['number']}"));
        self::assertSame(['anonymised@invalid', 'Anonymised', true, '0'], [$order['email'] ?? null, $this->rows([$order['billing'] ?? null])[0]['first_name'] ?? null, $order['anonymised'] ?? null, $order['refunded_total'] ?? null]);
        self::assertCount(1, $this->rows($this->data($this->request('GET', "/orders/{$first['number']}/invoices"))), 'the invoice stays');
        self::assertSame(200, $this->request('POST', "/orders/{$second['number']}/anonymise", [], nonce: true)->get_status(), 'erasing twice changes nothing');
        $again = $export('ada@example.com', 1);
        self::assertIsArray($again);
        self::assertSame([], $this->rows($again['data'] ?? null));
    }

    public function testTheShopRefundsPartOfAPaidOrderAndThenTheRest(): void
    {
        $order = $this->order([[$this->lamp, 2]]);
        $this->actAs('administrator');
        self::assertSame(422, $this->request('POST', "/orders/{$order['number']}/refunds", ['amount' => '1'], nonce: true)->get_status(), 'not paid yet');
        self::assertSame(200, $this->request('POST', "/orders/{$order['number']}/paid", [], nonce: true)->get_status());
        $total = $this->data($this->request('GET', "/orders/{$order['number']}"))['total'];
        self::assertIsString($total);
        $this->sentMail();
        $this->mails = [];

        $state = $this->data($this->request('GET', "/orders/{$order['number']}/refunds"));
        self::assertSame([$total, true, null, []], [$state['refundable'], $state['paid'], $state['provider'], $state['refunds']], 'bank transfers are refunded outside the shop');
        $made = $this->request('POST', "/orders/{$order['number']}/refunds", ['amount' => '25', 'lines' => [['line' => 0, 'quantity' => 1]], 'restock' => true, 'reason' => 'Returned.'], nonce: true);
        self::assertSame(201, $made->get_status(), (string) wp_json_encode($made->get_data()));
        $refund = $this->rows($this->data($made)['refunds'])[0];
        self::assertSame(['manual', 'done', '25'], [$refund['method'], $refund['status'], $refund['amount']]);
        self::assertSame(2, $this->data($this->request('GET', "/stock/product/{$this->lamp}"))['available'], 'the returned lamp is back in stock');
        $sent = $this->sentMail();
        self::assertStringContainsString("We refunded part of your order #{$order['number']}", $sent[0]['subject'] ?? '');

        $receipt = $this->data($this->request('GET', "/receipts/{$order['key']}"));
        self::assertSame(['processing', '25', 1], [$receipt['status'], $receipt['refunded_total'], $this->rows($receipt['lines'])[0]['refunded_quantity']]);
        self::assertSame(422, $this->request('POST', "/orders/{$order['number']}/refunds", ['amount' => '1000'], nonce: true)->get_status(), 'more than is left');

        $rest = $this->data($this->request('GET', "/orders/{$order['number']}/refunds"))['refundable'];
        $this->request('POST', "/orders/{$order['number']}/refunds", ['amount' => $rest], nonce: true);
        self::assertSame('refunded', $this->data($this->request('GET', "/orders/{$order['number']}"))['status']);

        $before = $this->data($this->request('GET', "/stock/product/{$this->lamp}"))['available'];
        self::assertIsInt($before);
        $restocked = $this->request('POST', "/orders/{$order['number']}/restock", ['lines' => [['line' => 0, 'quantity' => 1]]], nonce: true);
        self::assertSame(200, $restocked->get_status(), (string) wp_json_encode($restocked->get_data()));
        self::assertSame($before + 1, $this->data($this->request('GET', "/stock/product/{$this->lamp}"))['available'], 'the lamp refunded by amount came back');
        self::assertSame(422, $this->request('POST', "/orders/{$order['number']}/restock", ['lines' => []], nonce: true)->get_status());
    }

    public function testTheShopTakesAnOrderItselfAndDeletesItOnceCancelled(): void
    {
        $this->actAs('administrator');
        $billing = ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'street' => '1 Vitosha Blvd', 'city' => 'Sofia', 'postcode' => '1000', 'country' => 'BG', 'phone' => '+359 2 000 0000'];
        $items = [['product_id' => $this->lamp, 'quantity' => 2, 'unit_price' => '25']];

        $quote = $this->request('POST', '/orders/quote', ['items' => $items, 'billing_country' => 'BG'], nonce: true);
        self::assertSame(200, $quote->get_status(), (string) wp_json_encode($quote->get_data()));
        self::assertSame(['50', ['bank_transfer']], [$this->data($quote)['total'], array_column($this->rows($this->data($quote)['payment_options']), 'gateway')]);

        $created = $this->request('POST', '/orders', ['email' => 'ada@example.com', 'billing' => $billing, 'items' => $items, 'payment' => 'bank_transfer'], nonce: true);
        self::assertSame(201, $created->get_status(), (string) wp_json_encode($created->get_data()));
        $order = $this->data($created);
        self::assertIsInt($order['number']);
        self::assertSame(['on_hold', '50', 'Bank transfer'], [$order['status'], $order['total'], $order['payment_name']]);
        self::assertSame(1, $this->data($this->request('GET', "/stock/product/{$this->lamp}"))['available']);

        self::assertSame(422, $this->request('POST', '/orders', ['email' => 'ada@example.com', 'billing' => $billing, 'items' => []], nonce: true)->get_status(), 'an order has items');
        self::assertSame(422, $this->request('DELETE', "/orders/{$order['number']}", nonce: true)->get_status(), 'still open');
        $this->request('PUT', "/orders/{$order['number']}/status", ['status' => 'cancelled'], nonce: true);
        self::assertSame(3, $this->data($this->request('GET', "/stock/product/{$this->lamp}"))['available'], 'cancelling gave the stock back');
        $deleted = $this->request('DELETE', "/orders/{$order['number']}", nonce: true);
        self::assertSame(204, $deleted->get_status(), (string) wp_json_encode($deleted->get_data()));
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        self::assertSame('0', $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}orillacart_payments WHERE order_id = {$order['number']}"), 'the bank transfer that never came went along');
    }

    public function testEditingAnOrderThatRunsOutOfStockChangesNothing(): void
    {
        $order = $this->order([[$this->lamp, 2]]);
        $this->actAs('administrator');

        $edited = $this->request('PUT', "/orders/{$order['number']}/items", ['items' => [['product_id' => $this->lamp, 'quantity' => 3, 'unit_price' => '25']]], nonce: true);
        self::assertSame(200, $edited->get_status(), (string) wp_json_encode($edited->get_data()));
        self::assertSame('75', $this->data($edited)['total']);

        $tooMany = $this->request('PUT', "/orders/{$order['number']}/items", ['items' => [['product_id' => $this->lamp, 'quantity' => 4]]], nonce: true);
        self::assertSame(422, $tooMany->get_status());
        $after = $this->data($this->request('GET', "/orders/{$order['number']}"));
        self::assertSame(['75', 3], [$after['total'], $this->rows($after['lines'])[0]['quantity']], 'the order and its stock are as before');
        self::assertSame(0, $this->data($this->request('GET', "/stock/product/{$this->lamp}"))['available']);
    }

    public function testAVisitorMakesAnAccountAndDownloadsWhatTheyBought(): void
    {
        $this->actAs('administrator');
        $file = tempnam(sys_get_temp_dir(), 'guide');
        self::assertIsString($file);
        file_put_contents($file, '%PDF guide');
        $upload = new \WP_REST_Request('POST', self::NAMESPACE . "/products/{$this->guide}/downloads");
        $upload->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        $upload->set_file_params(['file' => ['name' => 'guide.pdf', 'type' => 'application/pdf', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => 10]]);
        $uploaded = rest_do_request($upload);
        self::assertSame(201, $uploaded->get_status(), (string) wp_json_encode($uploaded->get_data()));
        self::assertSame([], get_posts(['post_type' => 'attachment', 'numberposts' => -1]), 'not a media library item');
        $storage = $this->data($this->request('GET', '/download-storage?fresh=1'));
        self::assertNotTrue($storage['reachable'], 'the web server must not hand the files out by itself');
        wp_set_current_user(0);

        $order = $this->order([[$this->guide, 1]], ['create_account' => true]);
        self::assertFalse(email_exists('ada@example.com'), 'no account until the emailed link is followed');
        $confirmed = $this->request('POST', '/accounts/confirm', ['token' => $this->accountToken('ada@example.com'), 'password' => 'correct horse battery'], nonce: true);
        self::assertSame(201, $confirmed->get_status(), (string) wp_json_encode($confirmed->get_data()));
        $user = get_user_by('email', 'ada@example.com');
        self::assertInstanceOf(\WP_User::class, $user);
        $this->users[] = $user->ID;
        self::assertSame([WordPressAccounts::ROLE], $user->roles);
        self::assertStringStartsWith('customer-', $user->user_login, 'not the email\'s first part');
        self::assertSame($user->ID, get_current_user_id(), 'logged in once the account is made');
        self::assertSame('Sofia', $this->rows([$this->data($this->request('GET', '/account'))['billing']])[0]['city']);
        self::assertSame([$order['number']], array_column($this->rows($this->data($this->request('GET', '/account/orders'))['orders']), 'number'));
        self::assertSame([], $this->data($this->request('GET', '/account/downloads')), 'not paid yet');

        $this->actAs('administrator');
        $this->request('POST', "/orders/{$order['number']}/paid", [], nonce: true);
        wp_set_current_user($user->ID);
        $downloads = $this->rows($this->data($this->request('GET', '/account/downloads')));
        self::assertSame(['guide.pdf', 2], [$downloads[0]['name'], $downloads[0]['remaining']]);

        $files = self::kernel()->container()->get(Downloads::class);
        self::assertInstanceOf(Downloads::class, $files);
        $url = $downloads[0]['url'];
        self::assertIsString($url);
        $opened = $files->open(substr($url, -64));
        self::assertSame('%PDF guide', file_get_contents($opened->path));
        self::assertStringStartsWith(rtrim($this->text($storage['directory']), '/') . '/', $opened->path);
        self::assertStringStartsNotWith(rtrim(ABSPATH, '/') . '/wp-content/uploads/' . basename($opened->path), $opened->path);

        $this->actAs('administrator');
        self::assertSame([], $this->data($this->request('GET', "/orders/{$order['number']}/shipping-options")), 'nothing to ship');
        $grant = $this->rows($this->data($this->request('GET', "/orders/{$order['number']}"))['downloads'])[0]['id'];
        self::assertIsInt($grant);
        $more = $this->request('PUT', "/orders/{$order['number']}/downloads/{$grant}", ['remaining' => 5, 'expires_at' => '2030-01-31'], nonce: true);
        self::assertSame(200, $more->get_status(), (string) wp_json_encode($more->get_data()));
        self::assertSame(422, $this->request('PUT', "/orders/{$order['number']}/downloads/{$grant}", ['remaining' => -1], nonce: true)->get_status());
        self::assertSame(404, $this->request('PUT', "/orders/{$order['number']}/downloads/999999", ['remaining' => 1], nonce: true)->get_status());

        $guest = $this->request('PUT', "/orders/{$order['number']}/customer", ['customer_id' => null], nonce: true);
        self::assertSame(200, $guest->get_status(), (string) wp_json_encode($guest->get_data()));
        self::assertNull($this->data($guest)['customer_id']);
        self::assertSame(404, $this->request('PUT', "/orders/{$order['number']}/customer", ['customer_id' => 999999], nonce: true)->get_status(), 'no such account');
        wp_set_current_user($user->ID);
        self::assertSame([], $this->data($this->request('GET', '/account/orders'))['orders'], 'a guest\'s order now');

        $this->actAs('administrator');
        $this->request('PUT', "/orders/{$order['number']}/customer", ['customer_id' => $user->ID], nonce: true);
        wp_set_current_user($user->ID);
        $downloads = $this->rows($this->data($this->request('GET', '/account/downloads')));
        self::assertSame([5, '2030-01-31'], [$downloads[0]['remaining'], substr($this->text($downloads[0]['expires_at']), 0, 10)]);

        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($user->ID);
        $this->actAs('administrator');
        $kept = $this->data($this->request('GET', "/orders/{$order['number']}"));
        self::assertSame([null, 'ada@example.com'], [$kept['customer_id'], $kept['email']], 'a deleted account\'s order stays, as a guest\'s');
    }

    public function testAddOnsPriceRefuseAndActOnOrders(): void
    {
        unset($_COOKIE[CartCookie::NAME]);
        TestAddOn::$price = '7.5';
        try {
            $added = $this->request('POST', '/cart/items', ['product_id' => $this->lamp, 'quantity' => 2], nonce: true);
        } finally {
            TestAddOn::$price = null;
        }
        self::assertSame('15', $this->data($added)['subtotal'], 'the add-on\'s price');
        $refused = $this->request('POST', '/cart/items', ['product_id' => $this->guide, 'quantity' => 51], nonce: true);
        self::assertSame([422, 'At most 50 of an item.'], [$refused->get_status(), $this->data($refused)['message'] ?? null]);

        $order = $this->order([[$this->guide, 1]]);
        $this->actAs('administrator');
        self::assertSame([['id' => 'test_export', 'label' => 'Export']], $this->data($this->request('GET', "/orders/{$order['number']}/actions")));
        $ran = $this->request('POST', "/orders/{$order['number']}/actions/test_export", nonce: true);
        self::assertSame(200, $ran->get_status(), (string) wp_json_encode($ran->get_data()));
        self::assertSame("Exported #{$order['number']} for ada@example.com.", $this->data($ran)['message']);
        self::assertSame(404, $this->request('POST', "/orders/{$order['number']}/actions/nothing", nonce: true)->get_status());
    }

    public function testAnAddOnAttachesFilesAndHeadersToOrderEmails(): void
    {
        $invoice = (string) tempnam(sys_get_temp_dir(), 'invoice');
        $seen = [];
        $attach = static function (array $files, ?string $kind, ?int $number) use ($invoice, &$seen): array {
            $seen[] = [$kind, $number];

            return $kind === 'order_received' ? [...$files, $invoice, '/no/such/file.pdf'] : $files;
        };
        $header = static fn(array $headers): array => [...$headers, 'X-Shop: test'];
        add_filter('orillacart_email_attachments', $attach, 10, 3);
        add_filter('orillacart_email_headers', $header);
        try {
            $order = $this->order([[$this->lamp, 1]]);
            $mail = $this->sentMail();
        } finally {
            remove_filter('orillacart_email_attachments', $attach, 10);
            remove_filter('orillacart_email_headers', $header);
            unlink($invoice);
        }
        $toCustomer = array_values(array_filter($mail, static fn(array $sent): bool => $sent['to'] === 'ada@example.com'));
        self::assertSame([$invoice], $toCustomer[0]['attachments'], 'files that exist');
        self::assertContains('X-Shop: test', $toCustomer[0]['headers']);
        self::assertContains(['order_received', $order['number']], $seen);
        self::assertContains(['new_order', $order['number']], $seen, 'the shop\'s email too');
    }

    public function testSigningUpAnswersAlikeAndTheEmailSaysWhetherThereIsAnAccount(): void
    {
        $id = wp_insert_user(['user_login' => 'grace', 'user_email' => 'grace@example.com', 'user_pass' => 'correct horse battery']);
        self::assertIsInt($id);
        $this->users[] = $id;

        $answers = [];
        foreach (['grace@example.com', 'ada@example.com'] as $email) {
            $answer = $this->request('POST', '/accounts', ['email' => $email, 'first_name' => 'Ada', 'last_name' => 'Lovelace'], nonce: true);
            $answers[] = [$answer->get_status(), $this->data($answer)];
        }

        self::assertSame($answers[0], $answers[1], 'nothing tells them apart');
        self::assertSame(202, $answers[0][0]);
        self::assertSame([['grace@example.com', '[orillacart] You have an account already'], ['ada@example.com', '[orillacart] Make your account']], array_map(static fn(array $mail): array => [$mail['to'], $mail['subject']], $this->sentMail()));
        self::assertNotSame('', $this->accountToken('ada@example.com'));
    }

    public function testAnAddOnMayRefuseAFormAndAProgramFillsTheHoneypot(): void
    {
        $refuse = static fn(mixed $verdict, string $guard): mixed => $guard === 'accounts' ? new \WP_Error('captcha', '<strong>Error</strong>: Prove you are human.') : $verdict;
        add_filter('orillacart_verify_request', $refuse, 10, 2);
        try {
            $refused = $this->request('POST', '/accounts', ['email' => 'bot@example.com', 'first_name' => 'Bot', 'last_name' => 'Bot'], nonce: true);
        } finally {
            remove_filter('orillacart_verify_request', $refuse, 10);
        }
        self::assertSame([422, 'Error: Prove you are human.'], [$refused->get_status(), $this->data($refused)['message'] ?? null]);

        $filled = $this->request('POST', '/accounts', ['email' => 'bot@example.com', 'first_name' => 'Bot', 'last_name' => 'Bot', 'orillacart_website' => 'http://spam.test'], nonce: true);
        self::assertSame(422, $filled->get_status());
        self::assertSame([], $this->sentMail(), 'nothing was sent');
    }

    public function testAnAddressSendsSoManySignUpsInAWhile(): void
    {
        // The test site counts none; one address of its own, two sign-ups an hour.
        $limits = static fn(): array => ['accounts' => [2, 3600]];
        add_filter('orillacart_rate_limits', $limits, 20);
        $_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int(1, 254);
        try {
            $statuses = [];
            for ($attempt = 1; $attempt <= 3; ++$attempt) {
                $statuses[] = $this->request('POST', '/accounts', ['email' => "ada{$attempt}@example.com", 'first_name' => 'Ada', 'last_name' => 'Lovelace'], nonce: true)->get_status();
            }
        } finally {
            remove_filter('orillacart_rate_limits', $limits, 20);
            unset($_SERVER['REMOTE_ADDR']);
        }

        self::assertSame([202, 202, 429], $statuses);
    }

    /** The token of the last link to make an account the email got. */
    private function accountToken(string $email): string
    {
        foreach (array_reverse($this->sentMail()) as $mail) {
            if ($mail['to'] === $email && preg_match('/orillacart_account=([a-f0-9]{64})/', $mail['message'], $match) === 1) {
                return $match[1];
            }
        }

        return '';
    }

    /**
     * @param list<array{int, int}> $lines product id, quantity
     * @param array<string, mixed>  $extra
     *
     * @return array{number: int, key: string}
     */
    private function order(array $lines, array $extra = []): array
    {
        unset($_COOKIE[CartCookie::NAME]);
        foreach ($lines as [$product, $quantity]) {
            $added = $this->request('POST', '/cart/items', ['product_id' => $product, 'quantity' => $quantity], nonce: true);
            $cookie = $added->get_headers()['Set-Cookie'] ?? null;
            if (\is_string($cookie)) {
                $_COOKIE[CartCookie::NAME] = substr($cookie, \strlen(CartCookie::NAME) + 1, 64);
            }
        }
        $placed = $this->request('POST', '/checkout', [
            'email' => 'ada@example.com',
            'billing' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'street' => '1 Vitosha Blvd', 'city' => 'Sofia', 'postcode' => '1000', 'country' => 'BG', 'phone' => '+359 2 000 0000'],
            'payment' => 'bank_transfer',
        ] + $extra, nonce: true);
        self::assertSame(201, $placed->get_status(), (string) wp_json_encode($placed->get_data()));
        $order = $this->data($placed);
        self::assertIsInt($order['number']);
        self::assertIsString($order['receipt_url']);
        parse_str((string) parse_url($order['receipt_url'], \PHP_URL_QUERY), $query);
        self::assertIsString($query['orillacart_order'] ?? null);

        return ['number' => $order['number'], 'key' => $query['orillacart_order']];
    }

    /**
     * @param array<string, mixed> $details
     */
    private function product(string $name, array $details): int
    {
        $id = wp_insert_post(['post_type' => ProductPostType::NAME, 'post_title' => $name, 'post_status' => 'publish']);
        self::assertIsInt($id);
        $this->posts[] = $id;
        $saved = $this->request('PUT', "/products/{$id}", $details, nonce: true);
        self::assertSame(200, $saved->get_status(), (string) wp_json_encode($saved->get_data()));

        return $id;
    }

    /**
     * The shop's exporter or eraser, as WordPress's privacy tools find it.
     *
     * @param non-empty-string $filter
     */
    private function privacyCallback(string $filter): callable
    {
        $tools = apply_filters($filter, []);
        self::assertIsArray($tools);
        $tool = $tools['orillacart'] ?? null;
        self::assertIsArray($tool);
        self::assertIsCallable($tool['callback'] ?? null);

        return $tool['callback'];
    }

    private function id(\WP_REST_Response $response): int
    {
        $id = $this->data($response)['id'] ?? null;
        self::assertIsInt($id);

        return $id;
    }

    private function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var list<array<string, mixed>> */
        return array_values($value);
    }

    private function clean(): void
    {
        global $wpdb;
        self::assertInstanceOf(\wpdb::class, $wpdb);
        foreach (['pending_accounts', 'invoices', 'price_rules', 'customer_group_members', 'customer_groups', 'download_grants', 'order_notes', 'refunds', 'payments', 'payment_methods', 'order_lines', 'orders', 'cart_lines', 'carts', 'product_downloads', 'stock_levels', 'stockrooms', 'variations', 'products'] as $table) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}orillacart_{$table}");
        }
    }
}
