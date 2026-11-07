<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain;

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

/**
 * Reads a script into its syntax tree. Declarations at the top level; statements in function bodies; Twig's
 * expression grammar in both, with the declarations' values read declaratively (a bare word is a spec, not a
 * variable). A statement ends at the end of its line; a line cannot end inside brackets or after an operator.
 */
final class Parser
{
    private const array KEYWORDS = ['let', 'fn', 'if', 'elif', 'else', 'for', 'in', 'while', 'return', 'break', 'continue', 'try', 'catch', 'fail', 'true', 'false', 'null', 'and', 'or', 'not', 'is'];

    /** Binary operators by precedence, Twig's: higher binds tighter. */
    private const array BINARY = [
        'or' => 10, 'and' => 15,
        '==' => 20, '!=' => 20, '<' => 20, '>' => 20, '<=' => 20, '>=' => 20, 'in' => 20, 'not in' => 20, 'starts with' => 20, 'ends with' => 20,
        '..' => 25, '+' => 30, '-' => 30, '~' => 40, '*' => 60, '/' => 60, '//' => 60, '%' => 60, '??' => 300,
    ];
    private const int NOT_PRECEDENCE = 50;

    /** @var list<Token> */
    private array $tokens;
    private int $at = 0;

    public function __construct(string $source)
    {
        $this->tokens = Lexer::tokens($source);
    }

    public static function parse(string $source): Program
    {
        return new self($source)->program();
    }

    private function program(): Program
    {
        $declarations = [];
        $this->skipNewlines();
        while (!$this->peek()->is(TokenKind::End)) {
            $declarations[] = $this->declaration();
            $this->endOfLine();
            $this->skipNewlines();
        }

        return new Program($declarations, 1, 1);
    }

    /**
     * `kind word… "name" { props } fn(…) {…}`, or a top-level `fn name(…) {…}`.
     */
    private function declaration(): Declaration
    {
        $start = $this->expect(TokenKind::Word);
        if ($start->value === 'fn') {
            $name = $this->expect(TokenKind::Word)->value;

            return new Declaration('fn', [], $name, [], [], [], $this->fnRest($start), $start->line, $start->column);
        }
        if (\in_array($start->value, self::KEYWORDS, true)) {
            throw new SyntaxError(\sprintf('"%s" cannot start a declaration', $start->value), $start->line, $start->column);
        }
        $words = [];
        while ($this->peek()->is(TokenKind::Word) && $this->peek()->value !== 'fn') {
            $words[] = $this->next()->value;
        }
        $name = $this->peek()->is(TokenKind::Text) ? $this->next()->value : null;
        [$props, $functions, $nested] = $this->peek()->is(TokenKind::Punctuation, '{') ? $this->declarationBlock() : [[], [], []];
        $handler = null;
        if ($this->peek()->is(TokenKind::Word, 'fn')) {
            $handler = $this->fnRest($this->next());
        }

        return new Declaration($start->value, $words, $name, $props, $functions, $nested, $handler, $start->line, $start->column);
    }

    /**
     * @return array{array<string, Expr>, array<string, FnExpr>, list<Declaration>}
     */
    private function declarationBlock(): array
    {
        $this->expect(TokenKind::Punctuation, '{');
        $props = [];
        $functions = [];
        $nested = [];
        while (true) {
            $this->skipSeparators();
            if ($this->peek()->is(TokenKind::Punctuation, '}')) {
                $this->next();
                break;
            }
            $token = $this->peek();
            if ($token->is(TokenKind::Word, 'fn') && $this->peekAt(1)->is(TokenKind::Word)) {
                $this->next();
                $name = $this->expect(TokenKind::Word);
                if (isset($functions[$name->value])) {
                    throw new SyntaxError(\sprintf('The function "%s" is declared twice', $name->value), $name->line, $name->column);
                }
                $functions[$name->value] = $this->fnRest($token);
                continue;
            }
            if (($token->is(TokenKind::Word) || $token->is(TokenKind::Text)) && $this->peekAt(1)->is(TokenKind::Punctuation, ':')) {
                $this->next();
                $this->next();
                if (isset($props[$token->value])) {
                    throw new SyntaxError(\sprintf('"%s" is given twice', $token->value), $token->line, $token->column);
                }
                $this->skipNewlines();
                $props[$token->value] = $this->expression(true);
                continue;
            }
            if ($token->is(TokenKind::Word)) {
                $nested[] = $this->declaration();
                continue;
            }
            throw new SyntaxError(\sprintf('Expected a property, a function or a declaration, not %s', $token->describe()), $token->line, $token->column);
        }

        return [$props, $functions, $nested];
    }

    /** After the `fn` word, with or without a name: `(params) { body }`. */
    private function fnRest(Token $fn): FnExpr
    {
        $this->expect(TokenKind::Punctuation, '(');
        $params = [];
        $this->skipNewlines();
        while (!$this->peek()->is(TokenKind::Punctuation, ')')) {
            $param = $this->expect(TokenKind::Word);
            if (\in_array($param->value, self::KEYWORDS, true) || \in_array($param->value, $params, true)) {
                throw new SyntaxError(\sprintf('"%s" cannot be a parameter name', $param->value), $param->line, $param->column);
            }
            $params[] = $param->value;
            $this->skipNewlines();
            if (!$this->peek()->is(TokenKind::Punctuation, ')')) {
                $this->expect(TokenKind::Punctuation, ',');
                $this->skipNewlines();
            }
        }
        $this->next();

        return new FnExpr($params, $this->block(), $fn->line, $fn->column);
    }

    /**
     * `{ statements }`
     *
     * @return list<Stmt>
     */
    private function block(): array
    {
        $this->expect(TokenKind::Punctuation, '{');
        $statements = [];
        while (true) {
            $this->skipSeparators();
            if ($this->peek()->is(TokenKind::Punctuation, '}')) {
                $this->next();

                return $statements;
            }
            $statements[] = $this->statement();
            $token = $this->peek();
            if (!$token->is(TokenKind::Newline) && !$token->is(TokenKind::Punctuation, '}') && !$token->is(TokenKind::Punctuation, ';')) {
                throw new SyntaxError(\sprintf('Expected the end of the line after the statement, not %s', $token->describe()), $token->line, $token->column);
            }
        }
    }

    private function statement(): Stmt
    {
        $token = $this->peek();
        if ($token->is(TokenKind::Word)) {
            switch ($token->value) {
                case 'let':
                    $this->next();
                    $name = $this->expect(TokenKind::Word);
                    if (\in_array($name->value, self::KEYWORDS, true)) {
                        throw new SyntaxError(\sprintf('"%s" cannot be a variable name', $name->value), $name->line, $name->column);
                    }
                    $this->expect(TokenKind::Punctuation, '=');
                    $this->skipNewlines();

                    return new LetStmt($name->value, $this->expression(), $token->line, $token->column);
                case 'if':
                    return $this->ifStatement();
                case 'for':
                    $this->next();
                    $first = $this->expect(TokenKind::Word)->value;
                    $key = null;
                    $value = $first;
                    if ($this->peek()->is(TokenKind::Punctuation, ',')) {
                        $this->next();
                        $key = $first;
                        $value = $this->expect(TokenKind::Word)->value;
                    }
                    $this->expect(TokenKind::Word, 'in');
                    $iterable = $this->expression();

                    return new ForStmt($key, $value, $iterable, $this->block(), $token->line, $token->column);
                case 'while':
                    $this->next();
                    $test = $this->expression();

                    return new WhileStmt($test, $this->block(), $token->line, $token->column);
                case 'return':
                    $this->next();
                    $next = $this->peek();
                    $bare = $next->is(TokenKind::Newline) || $next->is(TokenKind::End) || $next->is(TokenKind::Punctuation, '}') || $next->is(TokenKind::Punctuation, ';');

                    return new ReturnStmt($bare ? null : $this->expression(), $token->line, $token->column);
                case 'break':
                    $this->next();

                    return new BreakStmt($token->line, $token->column);
                case 'continue':
                    $this->next();

                    return new ContinueStmt($token->line, $token->column);
                case 'try':
                    $this->next();
                    $body = $this->block();
                    $this->skipNewlines();
                    $this->expect(TokenKind::Word, 'catch');
                    $variable = $this->peek()->is(TokenKind::Word) ? $this->next()->value : null;

                    return new TryStmt($body, $variable, $this->block(), $token->line, $token->column);
                case 'fail':
                    $this->next();

                    return new FailStmt($this->expression(), $token->line, $token->column);
                case 'fn':
                case 'elif':
                case 'else':
                case 'catch':
                    throw new SyntaxError(\sprintf('"%s" cannot start a statement here', $token->value), $token->line, $token->column);
            }
        }
        $expr = $this->expression();
        if ($this->peek()->is(TokenKind::Punctuation, '=')) {
            if (!$expr instanceof Name && !$expr instanceof Member && !$expr instanceof Index) {
                throw new SyntaxError('Only a variable, or a member or index of one, can be assigned to', $expr->line, $expr->column);
            }
            $this->next();
            $this->skipNewlines();

            return new AssignStmt($expr, $this->expression(), $token->line, $token->column);
        }

        return new ExprStmt($expr, $token->line, $token->column);
    }

    private function ifStatement(): IfStmt
    {
        $start = $this->expect(TokenKind::Word, 'if');
        $branches = [[$this->expression(), $this->block()]];
        $else = null;
        while (true) {
            $mark = $this->at;
            $this->skipNewlines();
            if ($this->peek()->is(TokenKind::Word, 'elif')) {
                $this->next();
                $branches[] = [$this->expression(), $this->block()];
                continue;
            }
            if ($this->peek()->is(TokenKind::Word, 'else')) {
                $this->next();
                $else = $this->block();
            } else {
                $this->at = $mark;
            }
            break;
        }

        return new IfStmt($branches, $else, $start->line, $start->column);
    }

    /**
     * @param bool $declarative a bare word is a spec (`text`, `read`, `text { longest: 200 }`), not a variable
     */
    private function expression(bool $declarative = false, int $precedence = 0): Expr
    {
        $left = $this->unary($declarative);
        if ($declarative) {
            // A declaration's values are data: no operators, no calls.
            return $left;
        }
        while (true) {
            $op = $this->binaryOperator();
            if ($op === null || self::BINARY[$op] <= $precedence) {
                break;
            }
            $token = $this->peek();
            $this->consumeOperator($op);
            $this->skipNewlines();
            // `??` is right-associative; the rest left.
            $right = $this->expression($declarative, $op === '??' ? self::BINARY[$op] - 1 : self::BINARY[$op]);
            $left = new Binary($op, $left, $right, $token->line, $token->column);
        }
        if ($precedence === 0 && $this->peek()->is(TokenKind::Punctuation, '?')) {
            $token = $this->next();
            $this->skipNewlines();
            if ($this->peek()->is(TokenKind::Punctuation, ':')) {
                $this->next();
                $this->skipNewlines();

                return new Ternary($left, $left, $this->expression($declarative), $token->line, $token->column);
            }
            $then = $this->expression($declarative);
            $this->skipNewlines();
            $else = new Literal(null, $token->line, $token->column);
            if ($this->peek()->is(TokenKind::Punctuation, ':')) {
                $this->next();
                $this->skipNewlines();
                $else = $this->expression($declarative);
            }

            return new Ternary($left, $then, $else, $token->line, $token->column);
        }

        return $left;
    }

    /** The operator at the cursor, including the two-word ones; null when the expression ends here. */
    private function binaryOperator(): ?string
    {
        $token = $this->peek();
        if ($token->is(TokenKind::Word)) {
            $second = $this->peekAt(1);
            if ($token->value === 'not' && $second->is(TokenKind::Word, 'in')) {
                return 'not in';
            }
            if ($token->value === 'starts' && $second->is(TokenKind::Word, 'with')) {
                return 'starts with';
            }
            if ($token->value === 'ends' && $second->is(TokenKind::Word, 'with')) {
                return 'ends with';
            }

            return \in_array($token->value, ['or', 'and', 'in'], true) ? $token->value : null;
        }
        if ($token->is(TokenKind::Punctuation) && isset(self::BINARY[$token->value])) {
            return $token->value;
        }

        return null;
    }

    private function consumeOperator(string $op): void
    {
        $this->next();
        if (str_contains($op, ' ')) {
            $this->next();
        }
    }

    private function unary(bool $declarative): Expr
    {
        $token = $this->peek();
        if ($token->is(TokenKind::Word, 'not')) {
            $this->next();

            return new Unary('not', $this->expression($declarative, self::NOT_PRECEDENCE), $token->line, $token->column);
        }
        if ($token->is(TokenKind::Punctuation, '-') || $token->is(TokenKind::Punctuation, '+')) {
            $this->next();
            // Binds tighter than any binary operator: -a + b is (-a) + b.
            return new Unary($token->value, $this->postfix($this->primary($declarative), $declarative), $token->line, $token->column);
        }

        return $this->postfix($this->primary($declarative), $declarative);
    }

    private function postfix(Expr $expr, bool $declarative): Expr
    {
        while (true) {
            $token = $this->peek();
            if ($token->is(TokenKind::Punctuation, '.')) {
                $this->next();
                $name = $this->expect(TokenKind::Word);
                $expr = new Member($expr, $name->value, $token->line, $token->column);
            } elseif ($token->is(TokenKind::Punctuation, '[')) {
                $this->next();
                $this->skipNewlines();
                $key = $this->expression();
                $this->skipNewlines();
                $this->expect(TokenKind::Punctuation, ']');
                $expr = new Index($expr, $key, $token->line, $token->column);
            } elseif ($token->is(TokenKind::Punctuation, '(') && !$declarative) {
                $this->next();
                // A call is where its name starts, so a finding about it points at the name.
                $expr = new Call($expr, $this->arguments(), $expr->line, $expr->column);
            } elseif ($token->is(TokenKind::Punctuation, '|')) {
                $this->next();
                $name = $this->expect(TokenKind::Word);
                $args = [];
                if ($this->peek()->is(TokenKind::Punctuation, '(')) {
                    $this->next();
                    $args = $this->arguments();
                }
                $expr = new Filter($expr, $name->value, $args, $token->line, $token->column);
            } elseif ($token->is(TokenKind::Word, 'is')) {
                $this->next();
                $negated = false;
                if ($this->peek()->is(TokenKind::Word, 'not')) {
                    $this->next();
                    $negated = true;
                }
                $name = $this->expect(TokenKind::Word);
                $args = [];
                if ($this->peek()->is(TokenKind::Punctuation, '(')) {
                    $this->next();
                    $args = $this->arguments();
                }
                $expr = new Test($expr, $name->value, $args, $negated, $token->line, $token->column);
            } else {
                return $expr;
            }
        }
    }

    /**
     * After the opening parenthesis: `a, b, c)`.
     *
     * @return list<Expr>
     */
    private function arguments(): array
    {
        $args = [];
        $this->skipNewlines();
        while (!$this->peek()->is(TokenKind::Punctuation, ')')) {
            $args[] = $this->expression();
            $this->skipNewlines();
            if (!$this->peek()->is(TokenKind::Punctuation, ')')) {
                $this->expect(TokenKind::Punctuation, ',');
                $this->skipNewlines();
            }
        }
        $this->next();

        return $args;
    }

    private function primary(bool $declarative): Expr
    {
        $token = $this->next();
        switch ($token->kind) {
            case TokenKind::Number:
                return new Literal(str_contains($token->value, '.') ? (float) $token->value : (int) $token->value, $token->line, $token->column);
            case TokenKind::Text:
                return new Str($token->value, $token->line, $token->column);
            case TokenKind::Word:
                return match ($token->value) {
                    'true' => new Literal(true, $token->line, $token->column),
                    'false' => new Literal(false, $token->line, $token->column),
                    'null' => new Literal(null, $token->line, $token->column),
                    'fn' => $this->fnRest($token),
                    default => $this->word($token, $declarative),
                };
            case TokenKind::Punctuation:
                if ($token->value === '(') {
                    $this->skipNewlines();
                    $expr = $this->expression($declarative);
                    $this->skipNewlines();
                    $this->expect(TokenKind::Punctuation, ')');

                    return $expr;
                }
                if ($token->value === '[') {
                    $items = [];
                    $this->skipNewlines();
                    while (!$this->peek()->is(TokenKind::Punctuation, ']')) {
                        $items[] = $this->expression($declarative);
                        $this->skipNewlines();
                        if (!$this->peek()->is(TokenKind::Punctuation, ']')) {
                            $this->expect(TokenKind::Punctuation, ',');
                            $this->skipNewlines();
                        }
                    }
                    $this->next();

                    return new ListLit($items, $token->line, $token->column);
                }
                if ($token->value === '{') {
                    return $this->map($token, $declarative);
                }
                break;
            case TokenKind::Newline:
            case TokenKind::End:
                break;
        }

        throw new SyntaxError(\sprintf('Expected a value, not %s', $token->describe()), $token->line, $token->column);
    }

    private function word(Token $token, bool $declarative): Expr
    {
        if (\in_array($token->value, self::KEYWORDS, true)) {
            throw new SyntaxError(\sprintf('"%s" cannot be used as a value', $token->value), $token->line, $token->column);
        }
        if (!$declarative) {
            return new Name($token->value, $token->line, $token->column);
        }
        $props = [];
        if ($this->peek()->is(TokenKind::Punctuation, '{')) {
            [$props, $functions, $nested] = $this->declarationBlock();
            if ($functions !== [] || $nested !== []) {
                throw new SyntaxError(\sprintf('"%s" takes options only', $token->value), $token->line, $token->column);
            }
        }

        return new Spec($token->value, $props, $token->line, $token->column);
    }

    /** After the opening brace: `key: value, "key": value, (expr): value }`. */
    private function map(Token $open, bool $declarative): MapLit
    {
        $pairs = [];
        $this->skipNewlines();
        while (!$this->peek()->is(TokenKind::Punctuation, '}')) {
            $token = $this->next();
            if ($token->is(TokenKind::Word) || $token->is(TokenKind::Text) || $token->is(TokenKind::Number)) {
                $key = new Str($token->value, $token->line, $token->column);
            } elseif ($token->is(TokenKind::Punctuation, '(')) {
                $key = $this->expression($declarative);
                $this->expect(TokenKind::Punctuation, ')');
            } else {
                throw new SyntaxError(\sprintf('Expected a key, not %s', $token->describe()), $token->line, $token->column);
            }
            $this->expect(TokenKind::Punctuation, ':');
            $this->skipNewlines();
            $pairs[] = [$key, $this->expression($declarative)];
            $this->skipNewlines();
            if (!$this->peek()->is(TokenKind::Punctuation, '}')) {
                $this->expect(TokenKind::Punctuation, ',');
                $this->skipNewlines();
            }
        }
        $this->next();

        return new MapLit($pairs, $open->line, $open->column);
    }

    private function peek(): Token
    {
        return $this->tokens[$this->at];
    }

    private function peekAt(int $ahead): Token
    {
        return $this->tokens[min($this->at + $ahead, \count($this->tokens) - 1)];
    }

    private function next(): Token
    {
        $token = $this->tokens[$this->at];
        if ($token->kind !== TokenKind::End) {
            ++$this->at;
        }

        return $token;
    }

    private function expect(TokenKind $kind, ?string $value = null): Token
    {
        $token = $this->peek();
        if (!$token->is($kind, $value)) {
            $expected = $value !== null ? '"' . $value . '"' : match ($kind) {
                TokenKind::Word => 'a name',
                TokenKind::Number => 'a number',
                TokenKind::Text => 'a string',
                TokenKind::Punctuation => 'punctuation',
                TokenKind::Newline => 'the end of the line',
                TokenKind::End => 'the end of the file',
            };
            throw new SyntaxError(\sprintf('Expected %s, not %s', $expected, $token->describe()), $token->line, $token->column);
        }

        return $this->next();
    }

    private function skipNewlines(): void
    {
        while ($this->peek()->is(TokenKind::Newline)) {
            $this->next();
        }
    }

    /** Newlines, commas and semicolons between the entries of a block. */
    private function skipSeparators(): void
    {
        while ($this->peek()->is(TokenKind::Newline) || $this->peek()->is(TokenKind::Punctuation, ',') || $this->peek()->is(TokenKind::Punctuation, ';')) {
            $this->next();
        }
    }

    private function endOfLine(): void
    {
        $token = $this->peek();
        if (!$token->is(TokenKind::Newline) && !$token->is(TokenKind::End)) {
            throw new SyntaxError(\sprintf('Expected the end of the line, not %s', $token->describe()), $token->line, $token->column);
        }
    }
}
