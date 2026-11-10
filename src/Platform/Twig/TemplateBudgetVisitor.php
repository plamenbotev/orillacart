<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use Twig\Compiler;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\ArrowFunctionExpression;
use Twig\Node\Expression\Binary\RangeBinary;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\ForNode;
use Twig\Node\MacroNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * In an extension's template, `range` and `..`, `number_format` and `format` become TemplateBudgetExtension's
 * capped ones, and every `for` turn, macro call and arrow function call counts against the render's budget. It
 * runs after the sandbox has seen the names, so the policy allows them as the template wrote them. The plugin's
 * and the theme's templates are left alone.
 */
final class TemplateBudgetVisitor implements NodeVisitorInterface
{
    private const array FILTERS = ['number_format', 'format'];

    private bool $extension = false;

    public function enterNode(Node $node, Environment $env): Node
    {
        if ($node instanceof ModuleNode) {
            $this->extension = PackageTemplateLoader::extensionOf($node->getSourceContext()?->getPath() ?? '') !== null;
        }

        return $node;
    }

    public function leaveNode(Node $node, Environment $env): Node
    {
        if (!$this->extension) {
            return $node;
        }
        $line = $node->getTemplateLine();
        if ($node instanceof RangeBinary) {
            return new FunctionExpression(self::function($env, 'range', $node), new Nodes([$node->getNode('left'), $node->getNode('right')]), $line);
        }
        if ($node instanceof FunctionExpression && $node->getAttribute('name') === 'range') {
            return new FunctionExpression(self::function($env, 'range', $node), $node->getNode('arguments'), $line);
        }
        if ($node instanceof FilterExpression && \in_array($node->getAttribute('name'), self::FILTERS, true) && ($value = $node->getNode('node')) instanceof AbstractExpression) {
            $name = TemplateBudgetExtension::PREFIX . $node->getAttribute('name');
            $filter = $env->getFilter($name) ?? throw new SyntaxError('The extensions\' template budget is not available here.', $line, $node->getSourceContext());

            return new FilterExpression($value, $filter, $node->getNode('arguments'), $line);
        }
        if ($node instanceof ForNode || $node instanceof MacroNode) {
            $node->setNode('body', new Nodes([self::tick($line), $node->getNode('body')]));
        }
        if ($node instanceof ArrowFunctionExpression && ($expr = $node->getNode('expr')) instanceof AbstractExpression) {
            $node->setNode('expr', self::ticked($expr, $line));
        }

        return $node;
    }

    public function getPriority(): int
    {
        // After the sandbox's (0), which checks the names the template wrote.
        return 5;
    }

    private static function function(Environment $env, string $name, Node $node): \Twig\TwigFunction
    {
        return $env->getFunction(TemplateBudgetExtension::PREFIX . $name) ?? throw new SyntaxError('The extensions\' template budget is not available here.', $node->getTemplateLine(), $node->getSourceContext());
    }

    /** A statement counting one turn. */
    private static function tick(int $line): Node
    {
        return new class ($line) extends Node {
            public function __construct(int $line)
            {
                parent::__construct([], [], $line);
            }

            public function compile(Compiler $compiler): void
            {
                $compiler->write('$this->env->getExtension(')->repr(TemplateBudgetExtension::class)->raw(")->tick();\n");
            }
        };
    }

    /** The expression, counting one turn first. */
    private static function ticked(AbstractExpression $expr, int $line): AbstractExpression
    {
        return new class ($expr, $line) extends AbstractExpression {
            public function __construct(AbstractExpression $expr, int $line)
            {
                parent::__construct(['expr' => $expr], [], $line);
            }

            public function compile(Compiler $compiler): void
            {
                $compiler->raw('($this->env->getExtension(')->repr(TemplateBudgetExtension::class)->raw(')->tick() ?? ')->subcompile($this->getNode('expr'))->raw(')');
            }
        };
    }
}
