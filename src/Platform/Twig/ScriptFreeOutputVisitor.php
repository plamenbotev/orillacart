<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use Twig\Environment;
use Twig\Node\BlockNode;
use Twig\Node\MacroNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * Every piece of an extension's template that prints (its body, each block, each macro) prints through
 * ScriptFreeHtml (design 73): the extension's own templates and its overrides of the shop's, so a block the shop's
 * layout renders, or a macro the shop's template calls, is cleaned too. The plugin's, the theme's and the untrusted
 * folders' templates are left alone.
 */
final class ScriptFreeOutputVisitor implements NodeVisitorInterface
{
    public function enterNode(Node $node, Environment $env): Node
    {
        return $node;
    }

    public function leaveNode(Node $node, Environment $env): Node
    {
        if (!$node instanceof ModuleNode && !$node instanceof BlockNode && !$node instanceof MacroNode) {
            return $node;
        }
        if (PackageTemplateLoader::extensionOf($node->getSourceContext()?->getPath() ?? '') === null) {
            return $node;
        }
        $body = $node->getNode('body');
        if (!$body instanceof ScriptFreeOutputNode) {
            $node->setNode('body', new ScriptFreeOutputNode($body));
        }

        return $node;
    }

    public function getPriority(): int
    {
        // Last: what the sandbox and the words made of the body is what is wrapped.
        return 10;
    }
}
