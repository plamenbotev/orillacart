<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform;

use OrillaCart\Platform\Kernel;
use OrillaCart\Scripting\Application\Scripts;
use PHPUnit\Framework\TestCase;

/**
 * Every fenced example in docs/extension-scripts-guide.md compiles against the site's host functions. An example
 * without its own `extension` block gets one that grants every permission, so the check is on the code shown.
 */
final class ScriptGuideTest extends TestCase
{
    private const string HEADER = "extension \"guide\" {\n  needs: { orders: [read, write], products: [read, write], customers: [read, write], http: [\"api.payfast.example\", \"api.rates.example\", \"mail.example\"], templates: override }\n}\n";

    public function testEveryExampleCompiles(): void
    {
        $guide = (string) file_get_contents(\dirname(__DIR__, 3) . '/docs/extension-scripts-guide.md');
        // Every fence, in order, so a block in another language (```twig, ```html) is paired with its own closing fence and left out.
        preg_match_all('/^```(\w*)\n(.*?)^```$/ms', $guide, $fences, \PREG_SET_ORDER);
        $matches = [1 => array_values(array_map(static fn(array $fence): string => $fence[2], array_filter($fences, static fn(array $fence): bool => $fence[1] === '')))];
        self::assertNotEmpty($matches[1]);

        foreach (['DB_NAME' => 'guide', 'DB_USER' => 'guide', 'DB_PASSWORD' => '', 'DB_HOST' => 'localhost'] as $constant => $value) {
            \defined($constant) || \define($constant, $value);
        }
        if (!class_exists(\wpdb::class, false)) {
            require \dirname(__DIR__, 3) . '/tests/Fixtures/Database/wpdb.php';
        }
        $GLOBALS['wpdb'] = new \wpdb('guide', '', 'guide', 'localhost');
        $builder = Kernel::buildContainer(\dirname(__DIR__, 3));
        $builder->getDefinition(Scripts::class)->setPublic(true);
        $builder->compile();
        $builder->set(\OrillaCart\Platform\PluginContext::class, new \OrillaCart\Platform\PluginContext(\dirname(__DIR__, 3) . '/orillacart.php', 'guide'));
        $scripts = $builder->get(Scripts::class);
        self::assertInstanceOf(Scripts::class, $scripts);

        $compiled = 0;
        foreach ($matches[1] as $index => $example) {
            $source = (str_contains($example, 'extension "') ? '' : self::HEADER) . $example;
            $result = $scripts->compile($source, 'guide/' . $index);
            $first = strtok(trim($example), "\n");
            self::assertSame([], array_map(strval(...), $result->findings), \sprintf('Example %d ("%s") compiles', $index + 1, $first));
            ++$compiled;
        }
        self::assertGreaterThan(15, $compiled);
    }
}
