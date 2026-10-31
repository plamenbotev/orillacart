<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Assistant;

use OrillaCart\Assistant\Application\Dto\RouteTool;
use OrillaCart\Assistant\Application\RouteTools;
use OrillaCart\Platform\Kernel;
use OrillaCart\Shared\Http\Routing\Route;
use OrillaCart\Shared\Http\Routing\RouteCollection;
use OrillaCart\Shared\Http\Routing\RouteInputs;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Security\Capability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every shop manager's route is one of the assistant's tools, or its action says why not: the assistant covers
 * all administration because a new route is a new tool. Each tool says what it does, and its schema holds.
 */
final class RouteToolsTest extends TestCase
{
    private static ?RouteTools $tools = null;
    private static ?RouteCollection $routes = null;

    public function testEveryManagersRouteIsAToolOrSaysWhyNot(): void
    {
        $missing = [];
        foreach (self::routes()->all() as $route) {
            if ($route->signed || \in_array($route->capability, [Route::PUBLIC, Capability::ACCOUNT], true)) {
                continue;
            }
            $attribute = RouteTools::attribute($route->action, $route->path);
            if ($attribute?->leftOut !== null) {
                self::assertNotSame('', trim($attribute->leftOut), "{$route->action} leaves its route out without a reason.");
                continue;
            }
            foreach ($route->methods as $method) {
                $found = array_filter(self::catalogue()->all(), static fn(RouteTool $tool): bool => $tool->path === $route->path && $tool->method === $method);
                if ($found === []) {
                    $missing[] = "{$method} {$route->path}";
                }
            }
        }

        self::assertSame([], $missing, 'These routes are neither tools nor left out with #[Tool(leftOut: …)].');
    }

    #[DataProvider('tools')]
    public function testTheToolSaysWhatItDoes(RouteTool $tool): void
    {
        self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,63}$/', $tool->name);
        self::assertNotSame('', $tool->description, "{$tool->name}: write the first paragraph of its action's docblock.");
        self::assertStringNotContainsString('@', $tool->description);
    }

    #[DataProvider('tools')]
    public function testTheSchemaHolds(RouteTool $tool): void
    {
        $schema = $tool->inputSchema;
        self::assertSame('object', $schema['type']);
        self::assertFalse($schema['additionalProperties']);
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach (\is_array($schema['required'] ?? null) ? $schema['required'] : [] as $required) {
            self::assertIsString($required);
            self::assertArrayHasKey($required, $properties, "{$tool->name} requires {$required}, which it does not describe.");
        }
        foreach (array_values($tool->placeholders) as $argument) {
            self::assertArrayHasKey($argument, $properties);
        }
        $defs = \is_array($schema['$defs'] ?? null) ? $schema['$defs'] : [];
        array_walk_recursive($schema, static function (mixed $value, int|string $key) use ($defs, $tool): void {
            if ($key === '$ref') {
                self::assertIsString($value);
                self::assertArrayHasKey(substr($value, \strlen('#/$defs/')), $defs, "{$tool->name} refers to {$value}, which it does not define.");
            }
            if ($key === 'pattern') {
                self::assertIsString($value);
                self::assertNotFalse(@preg_match("\x01{$value}\x01u", ''), "{$tool->name} has a pattern that is not a pattern: {$value}");
            }
        });
        $encoded = json_encode($schema);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('"properties":[]', $encoded, 'An empty list where JSON Schema wants an object.');
    }

    /**
     * The tools that change something and run without the owner's yes, each with why: a change that moves no
     * money, tells no customer, deletes nothing, does not open or close the shop and changes no page customers
     * read. A new changing route is asked about until it is added here, so leaving one out is a choice.
     */
    private const array UNASKED = [
        'add_customer_address' => 'an address on a customer\'s account, nobody told',
        'add_customer_note' => 'a note for the shop only',
        'add_eu_standard_rates' => 'tax rates added to a group',
        'add_sample_data' => 'sample products and orders, removed with remove_sample_data (which asks)',
        'add_tax_exemption' => 'an exemption the shop records from a certificate; nobody is emailed',
        'assign_order' => 'which account an order is under; nobody is emailed',
        'cancel_shipment' => 'a booking cancelled with the courier before pickup; the customer is not told',
        'change_customer_address' => 'an address on a customer\'s account, nobody told',
        'change_download_allowance' => 'downloads left of a bought file',
        'change_download_delivery' => 'how downloads are delivered',
        'change_gift_card' => 'whether a gift card pays and until when; its balance stays',
        'change_order_contact' => 'corrects an order\'s addresses; nobody is emailed',
        'change_search_engine' => 'which search engine the shop uses',
        'change_stock_levels' => 'stock counts',
        'choose_order_columns' => 'columns of an export',
        'choose_product_columns' => 'columns of an export',
        'clear_problems' => 'only clears the problems list (Tool::NONE)',
        'create_attribute_set' => 'catalog setup',
        'create_carrier' => 'shipping setup',
        'create_coupon' => 'a coupon nobody has yet',
        'create_customer_group' => 'customer setup',
        'create_form' => 'a checkout form',
        'create_price_rule' => 'catalog pricing, as the admin sets it',
        'create_search_filter' => 'storefront search setup',
        'create_shipping_class' => 'shipping setup',
        'create_stockroom' => 'inventory setup',
        'create_tax_group' => 'tax setup',
        'create_tax_rate' => 'tax setup',
        'create_variation' => 'catalog',
        'edit_order_items' => 'an unpaid order on hold, priced from the catalog; nothing is charged',
        'finish_setup' => 'only stops the admin offering the setup',
        'import_tax_rates' => 'tax setup',
        'mark_order_handled' => 'only takes an order off the attention list (Tool::NONE)',
        'rebuild_reports' => 'reports worked out again',
        'rebuild_search_index' => 'the search index built again',
        'receive_return' => 'notes that returned items came back; the customer is not told',
        'rename_customer_group' => 'a name',
        'rename_download' => 'a name',
        'rename_shipping_class' => 'a name',
        'restart_workers' => 'background workers restarted',
        'restock_order' => 'refunded items back in stock',
        'retry_failed_jobs' => 'jobs that failed run as they would have',
        'run_order_action' => 'an add-on\'s own order action, which the add-on answers for',
        'save_email_template' => 'a template saved; nothing is sent',
        'save_product' => 'catalog',
        'send_test_email' => 'goes to the manager who asks, not to customers',
        'set_customer_groups' => 'customer setup',
        'start_product_import' => 'an import the manager uploaded',
        'update_attribute_set' => 'catalog setup',
        'update_carrier' => 'shipping setup',
        'update_coupon' => 'coupon setup',
        'update_currencies' => 'currency setup',
        'update_customer_settings' => 'settings',
        'update_email_settings' => 'settings',
        'update_eu_threshold' => 'tax setup',
        'update_form' => 'a checkout form',
        'update_gift_card_settings' => 'settings',
        'update_import_schemes' => 'tax setup',
        'update_invoice_settings' => 'settings',
        'update_newsletter_settings' => 'settings',
        'update_nexus_thresholds' => 'tax setup',
        'update_price_rule' => 'catalog pricing, as the admin sets it',
        'update_review_settings' => 'settings',
        'update_search_filter' => 'storefront search setup',
        'update_shop_pages' => 'which published pages the cart and checkout are on',
        'update_stock' => 'stock counts',
        'update_stockroom' => 'inventory setup',
        'update_tax_group' => 'tax setup',
        'update_tax_rate' => 'tax setup',
        'update_tax_settings' => 'settings',
        'update_variation' => 'catalog',
        'upload_download' => 'a file for a product',
        'upload_product_import' => 'a file for an import, which start_product_import runs',
    ];

    public function testEveryChangeTheOwnerIsNotAskedAboutIsChosen(): void
    {
        $unasked = [];
        foreach (self::catalogue()->all() as $tool) {
            if (!$tool->readOnly && $tool->risk === null) {
                $unasked[] = $tool->name;
            }
        }

        self::assertSame(array_keys(self::UNASKED), $unasked, 'A tool that changes something has no risk: give its action #[Tool(risk: …)], or add it to UNASKED with why it need not ask.');
    }

    public function testTheRisksAsTheOwnerWeighsThem(): void
    {
        $risks = [
            'mark_order_paid' => Tool::MONEY, 'refund_return' => Tool::MONEY, 'issue_gift_card' => Tool::MONEY,
            'adjust_order_price' => Tool::MONEY, 'give_up_refund' => Tool::MONEY, 'update_payment_method' => Tool::MONEY,
            'approve_return' => Tool::CUSTOMERS, 'refuse_return' => Tool::CUSTOMERS, 'send_shipment' => Tool::CUSTOMERS,
            'ready_for_pickup' => Tool::CUSTOMERS, 'picked_up' => Tool::CUSTOMERS, 'shipment_arrived' => Tool::CUSTOMERS,
            'new_gift_card_code' => Tool::CUSTOMERS, 'approve_tax_exemption' => Tool::CUSTOMERS, 'refuse_tax_exemption' => Tool::CUSTOMERS,
            'anonymise_order' => Tool::DELETING, 'update_legal_settings' => Tool::PUBLISHING, 'update_storefront_settings' => Tool::PUBLISHING,
            'mark_order_handled' => null, 'clear_problems' => null,
        ];
        foreach ($risks as $name => $risk) {
            $tool = self::catalogue()->get($name);
            self::assertNotNull($tool, $name);
            self::assertSame($risk, $tool->risk, $name);
            self::assertSame($risk !== null, $tool->destructive, "{$name}: destructive is what asks");
        }
    }

    public function testNoneIsARiskOnlyARouteCanSay(): void
    {
        self::assertNull(RouteTools::risk(new Tool(risk: Tool::NONE), 'DELETE'));
        self::assertSame(Tool::DELETING, RouteTools::risk(null, 'DELETE'));
        self::assertSame(Tool::MONEY, RouteTools::risk(new Tool(risk: Tool::MONEY), 'POST'));
        self::assertNotContains(Tool::NONE, Tool::RISKS, 'the owner has nothing to tick for it');
        $this->expectException(\InvalidArgumentException::class);
        new Tool(risk: 'sometimes');
    }

    #[DataProvider('tools')]
    public function testItsRiskIsTheRoutes(RouteTool $tool): void
    {
        if ($tool->method === 'DELETE') {
            $route = array_values(array_filter(self::routes()->all(), static fn($route): bool => $route->path === $tool->path))[0];
            $said = RouteTools::attribute($route->action, $route->path, 'DELETE')?->risk;
            self::assertTrue($tool->risk !== null || $said === Tool::NONE, "{$tool->name} deletes, or says what else it risks, or that it risks nothing (Tool::NONE).");
        }
        self::assertFalse($tool->readOnly && $tool->destructive, "{$tool->name} only reads, so it destroys nothing.");
        self::assertTrue($tool->risk === null || \in_array($tool->risk, Tool::RISKS, true));
    }

    #[DataProvider('tools')]
    public function testAnActionThatReadsTheBodyHasADtoTheSchemaComesFrom(RouteTool $tool): void
    {
        if ($tool->readOnly || $tool->input !== null || $tool->wholeBody !== null) {
            $this->addToAssertionCount(1);

            return;
        }
        $route = array_values(array_filter(self::routes()->all(), static fn($route): bool => $route->path === $tool->path))[0];
        self::assertTrue(class_exists($route->action));
        $code = (string) file_get_contents((string) new \ReflectionClass($route->action)->getFileName());
        self::assertStringNotContainsString('getParsedBody', $code, "{$tool->name} reads a body the assistant cannot describe: map it to a DTO, or give the assistant its own tool and leave this one out.");
        self::assertStringNotContainsString('->getBody()', $code, "{$tool->name} reads its body whole: say so with #[Tool(wholeBody: [name => what it is])].");
    }

    /** @return iterable<string, array{RouteTool}> */
    public static function tools(): iterable
    {
        foreach (self::catalogue()->all() as $tool) {
            yield $tool->name => [$tool];
        }
    }

    public function testNamesAreTheActions(): void
    {
        self::assertSame('change_order_status', RouteTools::nameOf('OrillaCart\Ordering\Http\ChangeOrderStatusAction'));
        self::assertSame('Ordering', RouteTools::areaOf('OrillaCart\Ordering\Http\ChangeOrderStatusAction'));
        self::assertNotNull(self::catalogue()->get('change_order_status'));
        self::assertSame(RouteInputs::summary('OrillaCart\Ordering\Http\ChangeOrderStatusAction'), self::catalogue()->get('change_order_status')->description);
    }

    private static function catalogue(): RouteTools
    {
        self::build();
        \assert(self::$tools instanceof RouteTools);

        return self::$tools;
    }

    private static function routes(): RouteCollection
    {
        self::build();
        \assert(self::$routes instanceof RouteCollection);

        return self::$routes;
    }

    private static function build(): void
    {
        if (self::$tools !== null) {
            return;
        }
        $builder = Kernel::buildContainer(\dirname(__DIR__, 3));
        $builder->getDefinition(RouteCollection::class)->setPublic(true);
        $builder->compile();
        $tools = $builder->get(RouteTools::class);
        $routes = $builder->get(RouteCollection::class);
        \assert($tools instanceof RouteTools && $routes instanceof RouteCollection);
        self::$tools = $tools;
        self::$routes = $routes;
    }
}
