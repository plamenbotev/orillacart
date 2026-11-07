<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application;

use OrillaCart\Scripting\Application\Dto\Compiled;
use OrillaCart\Scripting\Application\Dto\Finding;
use OrillaCart\Scripting\Domain\Ast\AssignStmt;
use OrillaCart\Scripting\Domain\Ast\Binary;
use OrillaCart\Scripting\Domain\Ast\BreakStmt;
use OrillaCart\Scripting\Domain\Ast\Call;
use OrillaCart\Scripting\Domain\Ast\ContinueStmt;
use OrillaCart\Scripting\Domain\Ast\Declaration;
use OrillaCart\Scripting\Domain\Ast\Expr;
use OrillaCart\Scripting\Domain\Ast\ExprStmt;
use OrillaCart\Scripting\Domain\Ast\FailStmt;
use OrillaCart\Scripting\Domain\Ast\Filter;
use OrillaCart\Scripting\Domain\Ast\FnExpr;
use OrillaCart\Scripting\Domain\Ast\ForStmt;
use OrillaCart\Scripting\Domain\Ast\IfStmt;
use OrillaCart\Scripting\Domain\Ast\Index;
use OrillaCart\Scripting\Domain\Ast\LetStmt;
use OrillaCart\Scripting\Domain\Ast\ListLit;
use OrillaCart\Scripting\Domain\Ast\Literal;
use OrillaCart\Scripting\Domain\Ast\MapLit;
use OrillaCart\Scripting\Domain\Ast\Member;
use OrillaCart\Scripting\Domain\Ast\Name;
use OrillaCart\Scripting\Domain\Ast\Node;
use OrillaCart\Scripting\Domain\Ast\Program;
use OrillaCart\Scripting\Domain\Ast\ReturnStmt;
use OrillaCart\Scripting\Domain\Ast\Spec;
use OrillaCart\Scripting\Domain\Ast\Stmt;
use OrillaCart\Scripting\Domain\Ast\Str;
use OrillaCart\Scripting\Domain\Ast\Ternary;
use OrillaCart\Scripting\Domain\Ast\Test;
use OrillaCart\Scripting\Domain\Ast\TryStmt;
use OrillaCart\Scripting\Domain\Ast\Unary;
use OrillaCart\Scripting\Domain\Ast\WhileStmt;
use OrillaCart\Scripting\Domain\Ops;

/**
 * Turns a parsed package into the PHP source of one class (CompiledScript): the declarations as data in its
 * manifest, each function a method. The generated code names nothing of PHP's but the runtime's helpers (Ops,
 * the Context): every operator is an Ops call, every host function goes through the context, every loop and
 * call counts a step. The static check runs as it compiles: unknown names and functions, wrong arities, filters
 * and tests that do not exist, permissions the extension did not declare, are findings, and no source is made.
 */
final class Compiler
{
    public const string NAMESPACE = 'OrillaCart\\Generated\\Scripts';
    private const string OPS = '\\' . Ops::class;
    private const string CATCHABLE = '\\OrillaCart\\Scripting\\Domain\\Catchable';

    /** @var list<Finding> */
    private array $findings = [];
    /** @var list<string> the methods' source */
    private array $methods = [];
    private int $methodCount = 0;
    private int $tempCount = 0;
    /** @var array<string, string> top-level function name → method */
    private array $topFunctions = [];
    /** @var list<string> the permissions the package declares */
    private array $needs = [];

    public function __construct(private readonly HostCatalog $catalog) {}

    /**
     * @param string $classSuffix what tells this class from every other: the package's id and checksum
     */
    public function compile(Program $program, string $classSuffix): Compiled
    {
        $this->findings = [];
        $this->methods = [];
        $this->methodCount = 0;
        $this->tempCount = 0;
        $this->topFunctions = [];
        $this->needs = [];
        $className = 'Script_' . preg_replace('/[^A-Za-z0-9_]/', '_', $classSuffix);

        foreach ($program->declarations as $declaration) {
            if ($declaration->kind === 'fn' && $declaration->name !== null) {
                if (isset($this->topFunctions[$declaration->name])) {
                    $this->finding($declaration, \sprintf('The function "%s" is declared twice', $declaration->name));
                }
                $this->topFunctions[$declaration->name] = 'fn_' . $declaration->name;
            }
            if ($declaration->kind === 'extension') {
                $this->needs = $this->declaredNeeds($declaration);
            }
        }
        $manifest = [];
        foreach ($program->declarations as $declaration) {
            if ($declaration->kind === 'fn' && $declaration->name !== null && $declaration->handler !== null) {
                $this->method($this->topFunctions[$declaration->name], $declaration->handler);
                continue;
            }
            $manifest[] = $this->declaration($declaration);
        }
        if ($this->findings !== []) {
            return new Compiled(self::NAMESPACE . '\\' . $className, null, $this->findings);
        }

        $source = "<?php\n\ndeclare(strict_types=1);\n\nnamespace " . self::NAMESPACE . ";\n\n"
            . "/** Generated from a script package: do not edit. */\n"
            . "final class {$className} extends \\" . CompiledScript::class . "\n{\n"
            . "    public static function manifest(): array\n    {\n        return " . $this->indent($this->phpArray($manifest), 2) . ";\n    }\n"
            . ($this->methods === [] ? '' : "\n" . implode("\n", $this->methods))
            . "}\n";

        return new Compiled(self::NAMESPACE . '\\' . $className, $source, []);
    }

    /**
     * `extension { needs: { orders: read, customers: [read, write], http: ["api.example"] } }` → orders:read,
     * customers:read, customers:write, http.
     *
     * @return list<string>
     */
    private function declaredNeeds(Declaration $declaration): array
    {
        $needs = [];
        $map = $declaration->props['needs'] ?? null;
        if (!$map instanceof MapLit) {
            return [];
        }
        foreach ($map->pairs as [$key, $value]) {
            if (!$key instanceof Str) {
                continue;
            }
            if ($key->value === 'http' || $key->value === 'templates') {
                $needs[] = $key->value;
                continue;
            }
            foreach ($value instanceof ListLit ? $value->items : [$value] as $item) {
                if ($item instanceof Spec) {
                    $needs[] = $key->value . ':' . $item->name;
                } elseif ($item instanceof Str) {
                    $needs[] = $key->value . ':' . $item->value;
                }
            }
        }

        return $needs;
    }

    /**
     * @return array<string, mixed> the declaration as the manifest holds it
     */
    private function declaration(Declaration $declaration): array
    {
        $props = [];
        foreach ($declaration->props as $key => $value) {
            $props[$key] = $this->declarative($value);
        }
        $functions = [];
        foreach ($declaration->functions as $name => $function) {
            $functions[$name] = $this->method(null, $function);
        }

        return [
            'kind' => $declaration->kind,
            'words' => $declaration->words,
            'name' => $declaration->name,
            'props' => $props,
            'functions' => $functions,
            'nested' => array_map($this->declaration(...), $declaration->nested),
            'handler' => $declaration->handler === null ? null : $this->method(null, $declaration->handler),
        ];
    }

    /** A declaration's value as data: literals, lists, maps, specs, and functions as their method names. */
    private function declarative(Expr $expr): mixed
    {
        if ($expr instanceof Literal) {
            return $expr->value;
        }
        if ($expr instanceof Str) {
            return $expr->value;
        }
        if ($expr instanceof ListLit) {
            return array_map($this->declarative(...), $expr->items);
        }
        if ($expr instanceof MapLit) {
            $map = [];
            foreach ($expr->pairs as [$key, $value]) {
                if (!$key instanceof Str) {
                    $this->finding($key, 'A declaration\'s keys are words or strings');
                    continue;
                }
                $map[$key->value] = $this->declarative($value);
            }

            return $map;
        }
        if ($expr instanceof Spec) {
            $spec = ['$spec' => $expr->name];
            foreach ($expr->props as $key => $value) {
                $spec[$key] = $this->declarative($value);
            }

            return $spec;
        }
        if ($expr instanceof FnExpr) {
            return $this->method(null, $expr);
        }
        if ($expr instanceof Unary && $expr->op === '-' && $expr->operand instanceof Literal && is_numeric($expr->operand->value)) {
            return -$expr->operand->value;
        }
        $this->finding($expr, 'Declarations take values, not code');

        return null;
    }

    /** Compiles a function to a method of the class; returns the method's name. */
    private function method(?string $name, FnExpr $fn): string
    {
        $name ??= 'm_' . ++$this->methodCount;
        $scope = new Scope($fn->params);
        $body = $this->body($fn->body, $scope, 2);
        $params = implode(', ', array_map(static fn(string $param): string => '$v_' . $param, $fn->params));
        $declared = array_diff($scope->declared(), $fn->params);
        $init = $declared === [] ? '' : '        $v_' . implode(' = $v_', $declared) . " = null;\n";
        $this->methods[] = "    public function {$name}({$params})\n    {\n        \$this->c->step();\n{$init}{$body}        return null;\n    }\n";

        return $name;
    }

    /**
     * @param list<Stmt> $statements
     */
    private function body(array $statements, Scope $scope, int $depth): string
    {
        $code = '';
        foreach ($statements as $statement) {
            $code .= $this->statement($statement, $scope, $depth);
        }

        return $code;
    }

    private function statement(Stmt $statement, Scope $scope, int $depth): string
    {
        $pad = str_repeat('    ', $depth);
        if ($statement instanceof LetStmt) {
            $value = $this->expr($statement->value, $scope);
            $scope->declare($statement->name);

            return "{$pad}\$v_{$statement->name} = {$value};\n";
        }
        if ($statement instanceof AssignStmt) {
            return $pad . $this->assignment($statement, $scope) . "\n";
        }
        if ($statement instanceof IfStmt) {
            $code = '';
            foreach ($statement->branches as $index => [$test, $body]) {
                $keyword = $index === 0 ? 'if' : '} elseif';
                $code .= "{$pad}{$keyword} (" . self::OPS . '::truthy(' . $this->expr($test, $scope) . ")) {\n" . $this->body($body, $scope, $depth + 1);
            }
            if ($statement->else !== null) {
                $code .= "{$pad}} else {\n" . $this->body($statement->else, $scope, $depth + 1);
            }

            return $code . "{$pad}}\n";
        }
        if ($statement instanceof ForStmt) {
            $iterable = $this->expr($statement->iterable, $scope);
            $scope->declare($statement->value);
            $as = '$v_' . $statement->value;
            if ($statement->key !== null) {
                $scope->declare($statement->key);
                $as = '$v_' . $statement->key . ' => ' . $as;
            }
            $scope->enterLoop();
            $body = $this->body($statement->body, $scope, $depth + 1);
            $scope->leaveLoop();

            return "{$pad}foreach (" . self::OPS . "::iterable({$iterable}) as {$as}) {\n{$pad}    \$this->c->step();\n{$body}{$pad}}\n";
        }
        if ($statement instanceof WhileStmt) {
            $test = $this->expr($statement->test, $scope);
            $scope->enterLoop();
            $body = $this->body($statement->body, $scope, $depth + 1);
            $scope->leaveLoop();

            return "{$pad}while (" . self::OPS . "::truthy({$test})) {\n{$pad}    \$this->c->step();\n{$body}{$pad}}\n";
        }
        if ($statement instanceof ReturnStmt) {
            return $pad . 'return ' . ($statement->value === null ? 'null' : $this->expr($statement->value, $scope)) . ";\n";
        }
        if ($statement instanceof BreakStmt || $statement instanceof ContinueStmt) {
            $word = $statement instanceof BreakStmt ? 'break' : 'continue';
            if (!$scope->inLoop()) {
                $this->finding($statement, \sprintf('"%s" belongs in a loop', $word));
            }

            return "{$pad}{$word};\n";
        }
        if ($statement instanceof TryStmt) {
            $body = $this->body($statement->body, $scope, $depth + 1);
            $caught = '$e_' . ++$this->tempCount;
            $assign = '';
            if ($statement->variable !== null) {
                $scope->declare($statement->variable);
                $assign = "{$pad}    \$v_{$statement->variable} = {$caught}->value();\n";
            }
            $handler = $this->body($statement->handler, $scope, $depth + 1);

            return "{$pad}try {\n{$body}{$pad}} catch (" . self::CATCHABLE . " {$caught}) {\n{$assign}{$handler}{$pad}}\n";
        }
        if ($statement instanceof FailStmt) {
            return "{$pad}throw " . self::OPS . '::failure(' . $this->expr($statement->error, $scope) . ");\n";
        }
        if ($statement instanceof ExprStmt) {
            return $pad . $this->expr($statement->expr, $scope) . ";\n";
        }

        throw new \LogicException('Unknown statement ' . $statement::class);
    }

    private function assignment(AssignStmt $statement, Scope $scope): string
    {
        $value = $this->expr($statement->value, $scope);
        $target = $statement->target;
        if ($target instanceof Name) {
            if (!$scope->has($target->name)) {
                $this->finding($target, \sprintf('"%s" is not declared: use "let %s = …" first', $target->name, $target->name));
            }

            return "\$v_{$target->name} = {$value};";
        }
        // a.b[c] = v: the root variable takes a copy with the member set.
        $path = [];
        while ($target instanceof Member || $target instanceof Index) {
            array_unshift($path, $target instanceof Member ? var_export($target->name, true) : self::OPS . '::key(' . $this->expr($target->key, $scope) . ')');
            $target = $target->object;
        }
        if (!$target instanceof Name || !$scope->has($target->name)) {
            $this->finding($statement->target, 'Only a variable, or a member or index of one, can be assigned to');

            return '';
        }

        return "\$v_{$target->name} = " . self::OPS . "::assign(\$v_{$target->name}, [" . implode(', ', $path) . "], {$value});";
    }

    private function expr(Expr $expr, Scope $scope): string
    {
        if ($expr instanceof Literal) {
            return var_export($expr->value, true);
        }
        if ($expr instanceof Str) {
            return var_export($expr->value, true);
        }
        if ($expr instanceof ListLit) {
            return '[' . implode(', ', array_map(fn(Expr $item): string => $this->expr($item, $scope), $expr->items)) . ']';
        }
        if ($expr instanceof MapLit) {
            $pairs = [];
            foreach ($expr->pairs as [$key, $value]) {
                $pairs[] = ($key instanceof Str ? var_export($key->value, true) : self::OPS . '::key(' . $this->expr($key, $scope) . ')') . ' => ' . $this->expr($value, $scope);
            }

            return '[' . implode(', ', $pairs) . ']';
        }
        if ($expr instanceof Name) {
            if ($scope->has($expr->name)) {
                return '$v_' . $expr->name;
            }
            $this->finding($expr, $this->catalog->knows($expr->name)
                ? \sprintf('"%s" is a namespace: call one of its functions', $expr->name)
                : \sprintf('Unknown name "%s"', $expr->name));

            return 'null';
        }
        if ($expr instanceof Member) {
            $root = $this->root($expr);
            if ($root !== null && !$scope->has($root->name)) {
                $this->finding($expr, $this->catalog->knows($root->name)
                    ? \sprintf('"%s" is a function or a namespace: call it', $this->dotted($expr))
                    : \sprintf('Unknown name "%s"', $root->name));

                return 'null';
            }

            return self::OPS . '::get(' . $this->expr($expr->object, $scope) . ', ' . var_export($expr->name, true) . ')';
        }
        if ($expr instanceof Index) {
            return self::OPS . '::get(' . $this->expr($expr->object, $scope) . ', ' . $this->expr($expr->key, $scope) . ')';
        }
        if ($expr instanceof Call) {
            return $this->call($expr, $scope);
        }
        if ($expr instanceof Filter) {
            if (!\in_array($expr->name, Ops::FILTERS, true)) {
                $this->finding($expr, \sprintf('Unknown filter "%s"', $expr->name));
            }

            return '$this->c->bytes(' . self::OPS . '::filter(' . var_export($expr->name, true) . ', ' . $this->expr($expr->value, $scope) . ', ' . $this->args($expr->args, $scope) . '))';
        }
        if ($expr instanceof Test) {
            if (!\in_array($expr->name, Ops::TESTS, true)) {
                $this->finding($expr, \sprintf('Unknown test "%s"', $expr->name));
            }

            return ($expr->negated ? '!' : '') . self::OPS . '::test(' . var_export($expr->name, true) . ', ' . $this->expr($expr->value, $scope) . ', ' . $this->args($expr->args, $scope) . ')';
        }
        if ($expr instanceof Unary) {
            $operand = $this->expr($expr->operand, $scope);

            return match ($expr->op) {
                'not' => '!' . self::OPS . "::truthy({$operand})",
                '-' => self::OPS . "::neg({$operand})",
                default => self::OPS . "::add(0, {$operand})",
            };
        }
        if ($expr instanceof Binary) {
            return $this->binary($expr, $scope);
        }
        if ($expr instanceof Ternary) {
            if ($expr->then === $expr->test) {
                $temp = '$t_' . ++$this->tempCount;

                return '(' . self::OPS . "::truthy({$temp} = " . $this->expr($expr->test, $scope) . ") ? {$temp} : " . $this->expr($expr->else, $scope) . ')';
            }

            return '(' . self::OPS . '::truthy(' . $this->expr($expr->test, $scope) . ') ? ' . $this->expr($expr->then, $scope) . ' : ' . $this->expr($expr->else, $scope) . ')';
        }
        if ($expr instanceof FnExpr) {
            return $this->lambda($expr, $scope);
        }
        if ($expr instanceof Spec) {
            $this->finding($expr, \sprintf('Unknown name "%s"', $expr->name));

            return 'null';
        }

        throw new \LogicException('Unknown expression ' . $expr::class);
    }

    private function binary(Binary $expr, Scope $scope): string
    {
        $left = $this->expr($expr->left, $scope);
        $right = $this->expr($expr->right, $scope);
        $ops = self::OPS;

        return match ($expr->op) {
            'or' => "({$ops}::truthy({$left}) || {$ops}::truthy({$right}))",
            'and' => "({$ops}::truthy({$left}) && {$ops}::truthy({$right}))",
            '==' => "{$ops}::eq({$left}, {$right})",
            '!=' => "!{$ops}::eq({$left}, {$right})",
            '<' => "({$ops}::cmp({$left}, {$right}) < 0)",
            '>' => "({$ops}::cmp({$left}, {$right}) > 0)",
            '<=' => "({$ops}::cmp({$left}, {$right}) <= 0)",
            '>=' => "({$ops}::cmp({$left}, {$right}) >= 0)",
            'in' => "{$ops}::in({$left}, {$right})",
            'not in' => "!{$ops}::in({$left}, {$right})",
            'starts with' => "{$ops}::startsWith({$left}, {$right})",
            'ends with' => "{$ops}::endsWith({$left}, {$right})",
            '..' => "\$this->c->bytes({$ops}::range({$left}, {$right}))",
            '+' => "{$ops}::add({$left}, {$right})",
            '-' => "{$ops}::sub({$left}, {$right})",
            '~' => "\$this->c->bytes({$ops}::concat({$left}, {$right}))",
            '*' => "{$ops}::mul({$left}, {$right})",
            '/' => "{$ops}::div({$left}, {$right})",
            '//' => "{$ops}::intdiv({$left}, {$right})",
            '%' => "{$ops}::mod({$left}, {$right})",
            '??' => "(({$left}) ?? ({$right}))",
            default => throw new \LogicException('Unknown operator ' . $expr->op),
        };
    }

    private function call(Call $expr, Scope $scope): string
    {
        $callee = $expr->callee;
        $args = $this->args($expr->args, $scope);
        if ($callee instanceof Name) {
            if ($scope->has($callee->name)) {
                return self::OPS . "::invoke(\$v_{$callee->name}, {$args})";
            }
            if (isset($this->topFunctions[$callee->name])) {
                return '$this->' . $this->topFunctions[$callee->name] . '(...' . $args . ')';
            }

            return $this->hostCall($callee->name, $expr, $args);
        }
        if ($callee instanceof Member) {
            $root = $this->root($callee);
            if ($root !== null && !$scope->has($root->name)) {
                return $this->hostCall($this->dotted($callee), $expr, $args);
            }
            $this->finding($expr, 'Values have no methods: use a filter, or a function of the host');

            return 'null';
        }

        return self::OPS . '::invoke(' . $this->expr($callee, $scope) . ", {$args})";
    }

    private function hostCall(string $name, Call $expr, string $args): string
    {
        $resolved = $this->catalog->resolve($name);
        if ($resolved === null) {
            $root = explode('.', $name)[0];
            $this->finding($expr, $this->catalog->knows($root) ? \sprintf('Unknown function "%s"', $name) : \sprintf('Unknown name "%s"', $root));

            return 'null';
        }
        [$function] = $resolved;
        $count = \count($expr->args);
        if ($count < $function->leastArguments || ($function->mostArguments !== null && $count > $function->mostArguments)) {
            $this->finding($expr, $function->mostArguments === $function->leastArguments
                ? \sprintf('"%s" takes %d argument%s, not %d', $name, $function->leastArguments, $function->leastArguments === 1 ? '' : 's', $count)
                : \sprintf('"%s" takes %d to %s arguments, not %d', $name, $function->leastArguments, $function->mostArguments ?? 'any number of', $count));
        }
        if ($function->needs !== null && !\in_array($function->needs, $this->needs, true)) {
            $this->finding($expr, \sprintf('"%s" needs the permission "%s": declare it in the extension\'s needs', $name, $function->needs));
        }

        return '$this->c->call(' . var_export($name, true) . ", {$args})";
    }

    private function lambda(FnExpr $fn, Scope $outer): string
    {
        $scope = new Scope([...$outer->declared(), ...$fn->params]);
        $body = $this->body($fn->body, $scope, 3);
        $params = implode(', ', array_map(static fn(string $param): string => '$v_' . $param, $fn->params));
        $captured = array_diff($outer->declared(), $fn->params);
        $use = $captured === [] ? '' : ' use ($v_' . implode(', $v_', $captured) . ')';
        $declared = array_diff($scope->declared(), $outer->declared(), $fn->params);
        $init = $declared === [] ? '' : '            $v_' . implode(' = $v_', $declared) . " = null;\n";

        return "(function ({$params}){$use} {\n            \$this->c->step();\n{$init}{$body}            return null;\n        })";
    }

    /**
     * @param list<Expr> $args
     */
    private function args(array $args, Scope $scope): string
    {
        return '[' . implode(', ', array_map(fn(Expr $arg): string => $this->expr($arg, $scope), $args)) . ']';
    }

    /** The Name a member chain starts from; null when it starts from a call or a literal. */
    private function root(Expr $expr): ?Name
    {
        while ($expr instanceof Member || $expr instanceof Index) {
            $expr = $expr->object;
        }

        return $expr instanceof Name ? $expr : null;
    }

    private function dotted(Expr $expr): string
    {
        $parts = [];
        while ($expr instanceof Member) {
            array_unshift($parts, $expr->name);
            $expr = $expr->object;
        }
        if ($expr instanceof Name) {
            array_unshift($parts, $expr->name);
        }

        return implode('.', $parts);
    }

    private function finding(Node $node, string $message): void
    {
        $this->findings[] = new Finding($node->line, $node->column, $message);
    }

    /** A PHP array literal of plain data. */
    private function phpArray(mixed $value): string
    {
        if (\is_array($value)) {
            $items = [];
            $list = array_is_list($value);
            foreach ($value as $key => $item) {
                $items[] = ($list ? '' : var_export($key, true) . ' => ') . $this->phpArray($item);
            }

            return '[' . implode(', ', $items) . ']';
        }

        return var_export($value, true);
    }

    private function indent(string $code, int $depth): string
    {
        return str_replace("\n", "\n" . str_repeat('    ', $depth), $code);
    }
}
