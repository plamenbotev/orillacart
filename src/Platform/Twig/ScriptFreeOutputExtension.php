<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use Twig\Extension\AbstractExtension;

/** What an extension's templates print, cleaned of what would run in the browser (design 73). */
final class ScriptFreeOutputExtension extends AbstractExtension
{
    public function getNodeVisitors(): array
    {
        return [new ScriptFreeOutputVisitor()];
    }
}
