<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Packages;

use OrillaCart\Extension\Setting;
use OrillaCart\Packages\Application\Dto\PackageManifest;
use OrillaCart\Scripting\Application\Compiler;
use OrillaCart\Scripting\Application\Host\Builtins;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Domain\Parser;
use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    public function testItReadsTheExtensionBlockItsNeedsAndItsSettings(): void
    {
        $source = <<<'OCS'
            extension "shop_x" {
              name: "Shop X"
              version: "2.1"
              needs: { orders: [read, write], customers: read, http: ["a.example", "b.example"] }
            }
            settings {
              key: secret { label: "API key", required: true, help: "From the dashboard" }
              mode: choice { of: ["live", "test"] }
              retries: number
              loud: boolean { label: "Loud" }
              note: text
              odd: thing
            }
            OCS;
        $compiled = new Compiler(HostCatalog::of(new Builtins()->functions()))->compile(Parser::parse($source), 'manifest_test');
        self::assertSame([], $compiled->findings);
        self::assertNotNull($compiled->source);
        eval(substr($compiled->source, 5));
        /** @var class-string<\OrillaCart\Scripting\Application\CompiledScript> $class */
        $class = $compiled->className;
        $manifest = PackageManifest::ofClass($class);

        self::assertSame(['Shop X', '2.1'], [$manifest->name(), $manifest->version()]);
        self::assertSame(['orders:read', 'orders:write', 'customers:read', 'http'], $manifest->needs());
        self::assertSame(['a.example', 'b.example'], $manifest->hosts());
        $settings = $manifest->settings();
        self::assertSame(['key', 'mode', 'retries', 'loud', 'note'], array_map(static fn(Setting $s): string => $s->key, $settings), 'an unknown kind is left out');
        self::assertSame([Setting::SECRET, 'API key', true, 'From the dashboard'], [$settings[0]->type, $settings[0]->label, $settings[0]->required, $settings[0]->help]);
        self::assertSame(['live' => 'live', 'test' => 'test'], $settings[1]->choices);
        self::assertSame('Retries', $settings[2]->label, 'a label made from the key');
    }

    public function testWithoutAnExtensionBlockThereIsNothingToSay(): void
    {
        $manifest = new PackageManifest([]);

        self::assertSame(['', '', [], []], [$manifest->name(), $manifest->version(), $manifest->needs(), $manifest->settings()]);
    }
}
