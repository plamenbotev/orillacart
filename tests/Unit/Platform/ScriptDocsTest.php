<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Platform;

use OrillaCart\Platform\Kernel;
use OrillaCart\Scripting\Application\ScriptDocumentation;
use PHPUnit\Framework\TestCase;

final class ScriptDocsTest extends TestCase
{
    public function testTheDocumentationIsCurrent(): void
    {
        // The container holds services built on the database's settings; nothing here connects.
        foreach (['DB_NAME' => 'docs', 'DB_USER' => 'docs', 'DB_PASSWORD' => '', 'DB_HOST' => 'localhost'] as $constant => $value) {
            \defined($constant) || \define($constant, $value);
        }
        if (!class_exists(\wpdb::class, false)) {
            require \dirname(__DIR__, 3) . '/tests/Fixtures/Database/wpdb.php';
        }
        $GLOBALS['wpdb'] = new \wpdb('docs', '', 'docs', 'localhost');
        $builder = Kernel::buildContainer(\dirname(__DIR__, 3));
        $builder->getDefinition(ScriptDocumentation::class)->setPublic(true);
        $builder->compile();
        $builder->set(\OrillaCart\Platform\PluginContext::class, new \OrillaCart\Platform\PluginContext(\dirname(__DIR__, 3) . '/orillacart.php', 'docs'));
        $docs = $builder->get(ScriptDocumentation::class);
        self::assertInstanceOf(ScriptDocumentation::class, $docs);

        self::assertSame($docs->markdown(), file_get_contents(\dirname(__DIR__, 3) . '/docs/extension-scripts.md'), 'Run bin/script-docs and commit docs/extension-scripts.md.');
    }
}
