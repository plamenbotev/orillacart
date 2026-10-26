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

    #[DataProvider('tools')]
    public function testItsRiskIsTheRoutes(RouteTool $tool): void
    {
        if ($tool->method === 'DELETE') {
            self::assertNotNull($tool->risk, "{$tool->name} deletes, or says what else it risks.");
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
