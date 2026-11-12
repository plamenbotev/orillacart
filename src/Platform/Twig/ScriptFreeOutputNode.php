<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Node;

/**
 * A piece of an extension's template, printed through ScriptFreeHtml (design 73): its output is captured as Twig's
 * own `{% apply %}` captures it, cleaned, and printed.
 */
#[YieldReady]
final class ScriptFreeOutputNode extends Node
{
    public function __construct(Node $body)
    {
        parent::__construct(['body' => $body], [], $body->getTemplateLine());
    }

    public function compile(Compiler $compiler): void
    {
        $useYield = $compiler->getEnvironment()->useYield();
        $compiler
            ->write('yield \\' . ScriptFreeHtml::class . '::clean(')
            ->raw($useYield ? "implode('', iterator_to_array(" : '\\Twig\\Extension\\CoreExtension::captureOutput(')
            ->raw("(function () use (&\$context, \$macros, \$blocks) {\n")
            ->indent()
            ->subcompile($this->getNode('body'))
            ->write("return; yield;\n")
            ->outdent()
            ->write('})()')
            ->raw($useYield ? ', false))' : ')')
            ->raw(");\n");
    }
}
