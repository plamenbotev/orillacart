<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Scripting;

use OrillaCart\Scripting\Domain\Lexer;
use OrillaCart\Scripting\Domain\SyntaxError;
use OrillaCart\Scripting\Domain\TokenKind;
use PHPUnit\Framework\TestCase;

final class LexerTest extends TestCase
{
    public function testItSplitsWordsNumbersStringsPunctuationAndLines(): void
    {
        $tokens = Lexer::tokens("let x = a.b(1, 2.5) ~ \"q\\\"\\n\" # comment\n  y <= 'raw\\n'");

        self::assertSame(
            [
                [TokenKind::Word, 'let'], [TokenKind::Word, 'x'], [TokenKind::Punctuation, '='], [TokenKind::Word, 'a'],
                [TokenKind::Punctuation, '.'], [TokenKind::Word, 'b'], [TokenKind::Punctuation, '('], [TokenKind::Number, '1'],
                [TokenKind::Punctuation, ','], [TokenKind::Number, '2.5'], [TokenKind::Punctuation, ')'], [TokenKind::Punctuation, '~'],
                [TokenKind::Text, "q\"\n"], [TokenKind::Newline, "\n"], [TokenKind::Word, 'y'], [TokenKind::Punctuation, '<='],
                [TokenKind::Text, 'raw\\n'], [TokenKind::End, ''],
            ],
            array_map(static fn($token): array => [$token->kind, $token->value], $tokens),
        );
        self::assertSame([2, 3], [$tokens[14]->line, $tokens[14]->column], 'the second line\'s word, after two spaces');
    }

    public function testTheLongestPunctuationWins(): void
    {
        $values = array_map(static fn($token): string => $token->value, Lexer::tokens('a ?? b .. c // d != e'));

        self::assertSame(['a', '??', 'b', '..', 'c', '//', 'd', '!=', 'e', ''], $values);
    }

    public function testAnUnclosedStringIsASyntaxErrorWhereItStarts(): void
    {
        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('The string is not closed before the end of the line (line 2, column 5)');

        Lexer::tokens("x\n  = \"open\n");
    }

    public function testAnUnknownCharacterIsASyntaxError(): void
    {
        $this->expectException(SyntaxError::class);
        $this->expectExceptionMessage('Unexpected character "@"');

        Lexer::tokens('a @ b');
    }
}
