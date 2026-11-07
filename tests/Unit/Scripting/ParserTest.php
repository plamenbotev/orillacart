<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Scripting;

use OrillaCart\Scripting\Domain\Ast\AssignStmt;
use OrillaCart\Scripting\Domain\Ast\Binary;
use OrillaCart\Scripting\Domain\Ast\Call;
use OrillaCart\Scripting\Domain\Ast\Filter;
use OrillaCart\Scripting\Domain\Ast\ForStmt;
use OrillaCart\Scripting\Domain\Ast\IfStmt;
use OrillaCart\Scripting\Domain\Ast\LetStmt;
use OrillaCart\Scripting\Domain\Ast\Literal;
use OrillaCart\Scripting\Domain\Ast\MapLit;
use OrillaCart\Scripting\Domain\Ast\Member;
use OrillaCart\Scripting\Domain\Ast\Name;
use OrillaCart\Scripting\Domain\Ast\ReturnStmt;
use OrillaCart\Scripting\Domain\Ast\Spec;
use OrillaCart\Scripting\Domain\Ast\Str;
use OrillaCart\Scripting\Domain\Ast\Ternary;
use OrillaCart\Scripting\Domain\Ast\Test;
use OrillaCart\Scripting\Domain\Ast\Unary;
use OrillaCart\Scripting\Domain\Parser;
use OrillaCart\Scripting\Domain\SyntaxError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    public function testDeclarationsHaveWordsANameOptionsFunctionsAndAHandler(): void
    {
        $program = Parser::parse(<<<'OCS'
            settings {
              merchant_id: text { label: "Merchant id", required: true }
              mode: choice { of: ["live", "test"] }
            }
            route GET "/tickets" { who: account } fn(request) { return 1 }
            payment_gateway "pay" {
              name: "Pay"
              fn start(session, settings) { return null }
              settings { key: secret }
            }
            OCS);

        [$settings, $route, $gateway] = $program->declarations;
        self::assertSame(['settings', [], null], [$settings->kind, $settings->words, $settings->name]);
        $merchant = $settings->props['merchant_id'];
        self::assertInstanceOf(Spec::class, $merchant);
        self::assertSame('text', $merchant->name);
        self::assertInstanceOf(Str::class, $merchant->props['label']);
        self::assertInstanceOf(Literal::class, $merchant->props['required']);
        $mode = $settings->props['mode'];
        self::assertInstanceOf(Spec::class, $mode);
        self::assertSame('choice', $mode->name);

        self::assertSame(['route', ['GET'], '/tickets'], [$route->kind, $route->words, $route->name]);
        self::assertInstanceOf(Spec::class, $route->props['who']);
        self::assertSame('account', $route->props['who']->name, 'a bare word in a declaration is a spec, not a variable');
        self::assertNotNull($route->handler);
        self::assertSame(['request'], $route->handler->params);
        self::assertInstanceOf(ReturnStmt::class, $route->handler->body[0]);

        self::assertSame('pay', $gateway->name);
        self::assertSame(['start'], array_keys($gateway->functions));
        self::assertSame(['session', 'settings'], $gateway->functions['start']->params);
        self::assertSame('settings', $gateway->nested[0]->kind);
    }

    public function testStatementsEndAtTheEndOfTheLineButNotInsideBracketsOrAfterAnOperator(): void
    {
        $program = Parser::parse(<<<'OCS'
            fn f(a) {
              let m = {
                x: 1,
                y: [2,
                  3],
              }
              let s = a ~
                "b"
              m.x = 2
              if a > 1 { return m }
              elif a is odd { return s }
              else { return null }
              for k, v in m { v = k }
              return a|upper|slice(0, 2)
            }
            OCS);

        $handler = $program->declarations[0]->handler;
        self::assertNotNull($handler);
        $body = $handler->body;
        self::assertCount(6, $body);
        self::assertInstanceOf(LetStmt::class, $body[0]);
        self::assertInstanceOf(MapLit::class, $body[0]->value);
        self::assertInstanceOf(LetStmt::class, $body[1]);
        self::assertInstanceOf(Binary::class, $body[1]->value);
        self::assertSame('~', $body[1]->value->op);
        self::assertInstanceOf(AssignStmt::class, $body[2]);
        self::assertInstanceOf(Member::class, $body[2]->target);
        self::assertInstanceOf(IfStmt::class, $body[3]);
        self::assertCount(2, $body[3]->branches);
        self::assertNotNull($body[3]->else);
        self::assertInstanceOf(Test::class, $body[3]->branches[1][0]);
        self::assertInstanceOf(ForStmt::class, $body[4]);
        self::assertSame(['k', 'v'], [$body[4]->key, $body[4]->value]);
        self::assertInstanceOf(ReturnStmt::class, $body[5]);
        self::assertInstanceOf(Filter::class, $body[5]->value);
        self::assertSame('slice', $body[5]->value->name);
        self::assertCount(2, $body[5]->value->args);
    }

    public function testOperatorsBindAsTwigsDo(): void
    {
        $expr = self::expression('1 + 2 * 3 ~ "x" == 7 and not b or c ?? d');

        // (((1 + ((2 * 3) ~ "x")) == 7) and (not b)) or (c ?? d): as in Twig, ~ binds tighter than +.
        self::assertInstanceOf(Binary::class, $expr);
        self::assertSame('or', $expr->op);
        self::assertInstanceOf(Binary::class, $expr->left);
        self::assertSame('and', $expr->left->op);
        self::assertInstanceOf(Binary::class, $expr->left->left);
        self::assertSame('==', $expr->left->left->op);
        self::assertInstanceOf(Binary::class, $expr->left->left->left);
        self::assertSame('+', $expr->left->left->left->op);
        self::assertInstanceOf(Binary::class, $expr->left->left->left->right);
        self::assertSame('~', $expr->left->left->left->right->op);
        self::assertInstanceOf(Binary::class, $expr->left->left->left->right->left);
        self::assertSame('*', $expr->left->left->left->right->left->op);
        self::assertInstanceOf(Unary::class, $expr->left->right);
        self::assertInstanceOf(Binary::class, $expr->right);
        self::assertSame('??', $expr->right->op);
    }

    public function testTheTernaryAndItsShortForms(): void
    {
        $full = self::expression('a ? b : c');
        self::assertInstanceOf(Ternary::class, $full);
        $short = self::expression('a ?: c');
        self::assertInstanceOf(Ternary::class, $short);
        self::assertSame($short->test, $short->then);
        $half = self::expression('a ? b');
        self::assertInstanceOf(Ternary::class, $half);
        self::assertInstanceOf(Literal::class, $half->else);
        self::assertNull($half->else->value);
    }

    public function testCallsMembersAndTwoWordOperators(): void
    {
        $expr = self::expression('objects.ticket.query({ limit: 1 }) not in x.y and "a" starts with "b"');

        self::assertInstanceOf(Binary::class, $expr);
        self::assertSame('and', $expr->op);
        self::assertInstanceOf(Binary::class, $expr->left);
        self::assertSame('not in', $expr->left->op);
        self::assertInstanceOf(Call::class, $expr->left->left);
        self::assertInstanceOf(Member::class, $expr->left->left->callee);
        self::assertInstanceOf(Name::class, $expr->left->left->callee->object instanceof Member ? $expr->left->left->callee->object->object : null);
        self::assertInstanceOf(Binary::class, $expr->right);
        self::assertSame('starts with', $expr->right->op);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badSources(): iterable
    {
        yield 'a keyword as a declaration' => ["if x { }\n", '"if" cannot start a declaration'];
        yield 'two statements on a line' => ["fn f() { let a = 1 let b = 2 }\n", 'Expected the end of the line after the statement, not "let"'];
        yield 'assigning to a call' => ["fn f() { g() = 1 }\n", 'Only a variable, or a member or index of one, can be assigned to'];
        yield 'a property twice' => ["settings { a: text, a: text }\n", '"a" is given twice'];
        yield 'a value where a key is' => ["fn f() { let m = { 1 + 2: 3 } }\n", 'Expected ":", not "+"'];
        yield 'code in a declaration' => ["route GET \"/x\" { who: a + b }\n", 'Expected a property, a function or a declaration, not "+"'];
        yield 'a keyword as a variable' => ["fn f() { let for = 1 }\n", '"for" cannot be a variable name'];
    }

    #[DataProvider('badSources')]
    public function testWhatCannotBeReadSaysWhere(string $source, string $message): void
    {
        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage($message);

        Parser::parse($source);
    }

    private static function expression(string $source): mixed
    {
        $program = Parser::parse("fn f(a, b, c, d, x) { return {$source} }\n");
        $return = $program->declarations[0]->handler?->body[0] ?? null;

        return $return instanceof ReturnStmt ? $return->value : null;
    }
}
