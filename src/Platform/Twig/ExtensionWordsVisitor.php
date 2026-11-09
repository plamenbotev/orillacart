<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use OrillaCart\Scripting\Application\Compiler;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Node\Expression\ConstantExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * In an extension's template, `__`, `_x`, `_n` and `_nx` become the same calls of the extension's words
 * (design 57), and `asset` the extension's file served (design 59): `__("Free shipping")` reads `extension__("payfast", "Free shipping")`, so the extension's
 * translations come first and the plugin's after. The words must be written as strings, as in its scripts. It runs
 * after the sandbox has seen the names, so the policy allows the calls as the template wrote them. The plugin's
 * and the theme's templates are left alone.
 */
final class ExtensionWordsVisitor implements NodeVisitorInterface
{
    /** What the call becomes: the extension's lookup of the same name. */
    public const string PREFIX = 'extension';
    /** `asset('portal.js')`: the extension's file under assets/, served (design 59); the name written as a string. */
    public const string ASSET = 'asset';
    /** The calls bound to the extension, each with the arguments that must be written as strings. */
    private const array BOUND = [self::ASSET => [0, null, null]];

    private ?string $extension = null;

    public function enterNode(Node $node, Environment $env): Node
    {
        if ($node instanceof ModuleNode) {
            $this->extension = PackageTemplateLoader::extensionOf($node->getSourceContext()?->getPath() ?? '');
        }

        return $node;
    }

    public function leaveNode(Node $node, Environment $env): Node
    {
        if ($this->extension === null || !$node instanceof FunctionExpression) {
            return $node;
        }
        $name = $node->getAttribute('name');
        $bound = Compiler::WORDS + self::BOUND;
        if (!\is_string($name) || !isset($bound[$name])) {
            return $node;
        }
        $line = $node->getTemplateLine();
        $args = [];
        foreach ($node->getNode('arguments') as $key => $arg) {
            if (!\is_int($key)) {
                throw new SyntaxError(\sprintf('"%s" takes its arguments in order, not by name.', $name), $line, $node->getSourceContext());
            }
            $args[] = $arg;
        }
        foreach ($bound[$name] as $index) {
            if ($index === null) {
                continue;
            }
            $arg = $args[$index] ?? null;
            if (!$arg instanceof ConstantExpression || !\is_string($arg->getAttribute('value'))) {
                throw new SyntaxError($name === self::ASSET
                    ? 'asset() takes the file\'s name written as a string ("portal.js"), so the check can see the file is in the package.'
                    : \sprintf('"%s" takes the words themselves, written as a string, so they can be listed for translators: put the values in after, as __("%%s left")|replace({"%%s": count}).', $name), $line, $node->getSourceContext());
            }
        }

        return new FunctionExpression($env->getFunction(self::PREFIX . $name) ?? throw new SyntaxError($name === self::ASSET ? 'The extensions\' assets are not available here.' : 'The extensions\' words are not available here.', $line, $node->getSourceContext()), new Nodes([new ConstantExpression($this->extension, $line), ...$args]), $line);
    }

    public function getPriority(): int
    {
        // After the sandbox's (0), which checks the names the template wrote.
        return 5;
    }
}
