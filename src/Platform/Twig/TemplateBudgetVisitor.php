<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Twig;

use Twig\Compiler;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Node\BlockNode;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\ArrowFunctionExpression;
use Twig\Node\Expression\Binary\ConcatBinary;
use Twig\Node\Expression\Binary\RangeBinary;
use Twig\Node\Expression\FilterExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\ForNode;
use Twig\Node\MacroNode;
use Twig\Node\ModuleNode;
use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\Node\SetNode;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * In an extension's template, `range` and `..`, `number_format`, `format`, `replace` and `~` become
 * TemplateBudgetExtension's capped ones, every `for` turn, macro call and arrow function call counts against the
 * render's budget, every `set` checks the memory, and the template's body and each block nest. It
 * runs after the sandbox has seen the names, so the policy allows them as the template wrote them. The plugin's
 * and the theme's templates are left alone.
 */
final class TemplateBudgetVisitor implements NodeVisitorInterface
{
    private const array FILTERS = ['number_format', 'format', 'replace'];

    private bool $extension = false;

    public function enterNode(Node $node, Environment $env): Node
    {
        if ($node instanceof ModuleNode) {
            $this->extension = SandboxedSources::budgeted($node->getSourceContext()?->getPath() ?? '');
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
        if ($node instanceof ConcatBinary) {
            return self::concat($node->getNode('left'), $node->getNode('right'), $line);
        }
        if ($node instanceof SetNode) {
            return new Nodes([$node, self::statement('memory()', $line)]);
        }
        if ($node instanceof BlockNode || $node instanceof ModuleNode) {
            $node->setNode('body', new Nodes([self::statement('nest();' . "\n" . 'try {', $line), $node->getNode('body'), self::raw('} finally { $this->env->getExtension(' . var_export(TemplateBudgetExtension::class, true) . ')->unnest(); }', $line)]));
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
        return self::statement('tick();', $line);
    }

    /** A statement calling the budget: `$this->env->getExtension(…)->` and then $call. */
    private static function statement(string $call, int $line): Node
    {
        return self::raw('$this->env->getExtension(' . var_export(TemplateBudgetExtension::class, true) . ')->' . (str_ends_with($call, '{') || str_ends_with($call, ';') ? $call : $call . ';'), $line);
    }

    /** A line of PHP of this class's own making, never of a template's. */
    private static function raw(string $code, int $line): Node
    {
        return new class ($code, $line) extends Node {
            public function __construct(private readonly string $code, int $line)
            {
                parent::__construct([], [], $line);
            }

            public function compile(Compiler $compiler): void
            {
                $compiler->write($this->code . "\n");
            }
        };
    }

    /** `left ~ right`, by the budget's concat. */
    private static function concat(Node $left, Node $right, int $line): AbstractExpression
    {
        return new class ($left, $right, $line) extends AbstractExpression {
            public function __construct(Node $left, Node $right, int $line)
            {
                parent::__construct(['left' => $left, 'right' => $right], [], $line);
            }

            public function compile(Compiler $compiler): void
            {
                $compiler->raw('$this->env->getExtension(')->repr(TemplateBudgetExtension::class)->raw(')->concat(')->subcompile($this->getNode('left'))->raw(', ')->subcompile($this->getNode('right'))->raw(')');
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
