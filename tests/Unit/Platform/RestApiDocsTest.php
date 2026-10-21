<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform;

use OrillaCart\Platform\Http\RouteDocumentation;
use PHPUnit\Framework\TestCase;

/**
 * docs/rest-api.md is what the routes say now: a route added or changed without running bin/rest-docs fails here.
 */
final class RestApiDocsTest extends TestCase
{
    public function testTheDocumentationIsCurrent(): void
    {
        $root = \dirname(__DIR__, 3);

        self::assertSame(RouteDocumentation::ofPlugin($root)->markdown(), (string) file_get_contents($root . '/docs/rest-api.md'), 'Run bin/rest-docs and commit docs/rest-api.md.');
    }
}
