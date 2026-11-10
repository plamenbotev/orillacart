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
     * The tools that change something and run without the owner's yes. Each one's action says why with
     * #[Tool(unasked: …)]: a change that moves no money, tells no customer, deletes nothing, does not open or
     * close the shop and changes no page customers read. The list makes adding one a change seen here too.
     */
    private const array UNASKED = [
        'add_customer_address',
        'add_customer_note',
        'add_eu_standard_rates',
        'add_sample_data',
        'cancel_shipment',
        'change_customer_address',
        'change_search_engine',
        'change_stock_levels',
        'choose_order_columns',
        'choose_product_columns',
        'clear_problems',
        'create_attribute_set',
        'create_carrier',
        'create_customer_group',
        'create_form',
        'create_search_filter',
        'create_shipping_class',
        'create_stockroom',
        'create_tax_group',
        'create_tax_rate',
        'create_variation',
        'disable_package',
        'finish_setup',
        'import_package',
        'import_tax_rates',
        'install_package',
        'mark_order_handled',
        'preview_package',
        'rebuild_reports',
        'rebuild_search_index',
        'receive_return',
        'rename_customer_group',
        'rename_download',
        'rename_shipping_class',
        'restart_workers',
        'restock_order',
        'restore_package_version',
        'retry_failed_jobs',
        'save_package_settings',
        'save_package_translations',
        'save_product',
        'save_record_fields',
        'send_test_email',
        'set_customer_groups',
        'start_product_import',
        'update_attribute_set',
        'update_carrier',
        'update_customer_settings',
        'update_eu_threshold',
        'update_form',
        'update_gift_card_settings',
        'update_import_schemes',
        'update_invoice_settings',
        'update_newsletter_settings',
        'update_nexus_thresholds',
        'update_review_settings',
        'update_search_filter',
        'update_stock',
        'update_stockroom',
        'update_tax_group',
        'update_tax_rate',
        'update_tax_settings',
        'update_variation',
        'upload_download',
        'upload_package_archive',
        'upload_product_import',
    ];

    public function testEveryChangeSaysWhatItRisksOrWhyItNeedNotAsk(): void
    {
        $unmarked = [];
        foreach (self::catalogue()->all() as $tool) {
            if ($tool->readOnly || $tool->method === 'DELETE') {
                continue;
            }
            $said = RouteTools::attribute(self::action($tool), $tool->path, $tool->method);
            if ($said?->risk === null && $said?->unasked === null) {
                $unmarked[] = $tool->name;
            }
        }

        self::assertSame([], $unmarked, 'These tools change something and say neither what they risk nor why they need not ask: give their action #[Tool(risk: …)] or #[Tool(unasked: \'why\')]. Until then they ask as Tool::CHANGES.');
    }

    public function testEveryChangeTheOwnerIsNotAskedAboutIsChosen(): void
    {
        $unasked = [];
        foreach (self::catalogue()->all() as $tool) {
            if (!$tool->readOnly && $tool->risk === null) {
                self::assertNotNull($tool->unasked, "{$tool->name} runs unasked without saying why.");
                $unasked[] = $tool->name;
            }
        }

        self::assertSame(self::UNASKED, $unasked, 'A tool that changes something runs unasked only when its action says why with #[Tool(unasked: …)] and it is listed in UNASKED.');
    }

    public function testAChangeThatSaysNothingAsks(): void
    {
        self::assertSame(Tool::CHANGES, RouteTools::risk(null, 'POST'));
        self::assertSame(Tool::CHANGES, RouteTools::risk(new Tool(name: 'something'), 'PUT'));
        self::assertSame(Tool::DELETING, RouteTools::risk(null, 'DELETE'));
        self::assertNull(RouteTools::risk(null, 'POST', readOnly: true));
        self::assertNull(RouteTools::risk(new Tool(unasked: 'only clears a flag'), 'DELETE'));
        self::assertSame(Tool::MONEY, RouteTools::risk(new Tool(risk: Tool::MONEY), 'POST'));
        self::assertContains(Tool::CHANGES, Tool::RISKS, 'the owner can tick it');
    }

    public function testAToolRunsUnaskedOnlyWithAReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Tool(unasked: ' ');
    }

    public function testAToolEitherRisksOrRunsUnasked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Tool(risk: Tool::MONEY, unasked: 'nothing is charged');
    }

    public function testTheRisksAsTheOwnerWeighsThem(): void
    {
        $risks = [
            'send_customer_welcome' => Tool::CUSTOMERS, 'create_customer' => Tool::CUSTOMERS, 'resend_customer_invitation' => Tool::CUSTOMERS, 'mark_order_paid' => Tool::MONEY, 'refund_return' => Tool::MONEY, 'issue_gift_card' => Tool::MONEY,
            'adjust_order_price' => Tool::MONEY, 'give_up_refund' => Tool::MONEY, 'update_payment_method' => Tool::MONEY,
            'approve_return' => Tool::CUSTOMERS, 'refuse_return' => Tool::CUSTOMERS, 'send_shipment' => Tool::CUSTOMERS,
            'ready_for_pickup' => Tool::CUSTOMERS, 'picked_up' => Tool::CUSTOMERS, 'shipment_arrived' => Tool::CUSTOMERS,
            'new_gift_card_code' => Tool::CUSTOMERS, 'approve_tax_exemption' => Tool::CUSTOMERS, 'refuse_tax_exemption' => Tool::CUSTOMERS,
            'anonymise_order' => Tool::DELETING, 'update_legal_settings' => Tool::PUBLISHING, 'update_storefront_settings' => Tool::PUBLISHING,
            'edit_order_items' => Tool::MONEY, 'change_gift_card' => Tool::MONEY, 'update_currencies' => Tool::MONEY, 'add_tax_exemption' => Tool::MONEY,
            'update_shop_pages' => Tool::PUBLISHING, 'run_order_action' => Tool::CHANGES, 'change_download_allowance' => Tool::CHANGES,
            'change_download_delivery' => Tool::CHANGES, 'mark_order_handled' => null, 'clear_problems' => null,
        ];
        foreach ($risks as $name => $risk) {
            $tool = self::catalogue()->get($name);
            self::assertNotNull($tool, $name);
            self::assertSame($risk, $tool->risk, $name);
            self::assertSame($risk !== null, $tool->destructive, "{$name}: destructive is what asks");
        }
    }

    public function testAnUnknownRiskIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Tool(risk: 'sometimes');
    }

    #[DataProvider('tools')]
    public function testItsRiskIsTheRoutes(RouteTool $tool): void
    {
        if (!$tool->readOnly) {
            $said = RouteTools::attribute(self::action($tool), $tool->path, $tool->method);
            self::assertTrue($tool->risk !== null || $said?->unasked !== null, "{$tool->name} changes something, so it asks, or says why not.");
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

    private static function action(RouteTool $tool): string
    {
        foreach (self::routes()->all() as $route) {
            if ($route->path === $tool->path && \in_array($tool->method, $route->methods, true)) {
                return $route->action;
            }
        }
        self::fail("No route makes {$tool->name}.");
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
