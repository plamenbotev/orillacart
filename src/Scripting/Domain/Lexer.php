<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain;

/**
 * Splits a script into tokens. Newlines are tokens, as they end statements; the parser drops them where a line
 * cannot end (inside brackets, after an operator or a comma). A `#` starts a comment to the end of the line.
 */
final class Lexer
{
    /** Longest first, so `<=` is not read as `<` then `=`. */
    private const array PUNCTUATION = [
        '...', '==', '!=', '<=', '>=', '??', '..', '//',
        '(', ')', '[', ']', '{', '}', ',', ':', ';', '.', '=', '<', '>', '+', '-', '*', '/', '%', '~', '?', '|',
    ];

    private int $offset = 0;
    private int $line = 1;
    private int $lineStart = 0;

    public function __construct(private readonly string $source) {}

    /**
     * @return list<Token>
     */
    public static function tokens(string $source): array
    {
        return new self($source)->all();
    }

    /**
     * @return list<Token>
     */
    private function all(): array
    {
        $tokens = [];
        $length = \strlen($this->source);
        while ($this->offset < $length) {
            $char = $this->source[$this->offset];
            if ($char === "\n") {
                $tokens[] = $this->token(TokenKind::Newline, "\n");
                ++$this->offset;
                ++$this->line;
                $this->lineStart = $this->offset;
                continue;
            }
            if ($char === ' ' || $char === "\t" || $char === "\r") {
                ++$this->offset;
                continue;
            }
            if ($char === '#') {
                while ($this->offset < $length && $this->source[$this->offset] !== "\n") {
                    ++$this->offset;
                }
                continue;
            }
            if (ctype_alpha($char) || $char === '_') {
                $tokens[] = $this->word();
                continue;
            }
            if (ctype_digit($char)) {
                $tokens[] = $this->number();
                continue;
            }
            if ($char === '"' || $char === "'") {
                $tokens[] = $this->string($char);
                continue;
            }
            $tokens[] = $this->punctuation();
        }
        $tokens[] = $this->token(TokenKind::End, '');

        return $tokens;
    }

    private function word(): Token
    {
        preg_match('/[A-Za-z_][A-Za-z0-9_]*/A', $this->source, $match, 0, $this->offset);
        $word = $match[0] ?? '';
        $token = $this->token(TokenKind::Word, $word);
        $this->offset += \strlen($word);

        return $token;
    }

    private function number(): Token
    {
        preg_match('/[0-9]+(\.[0-9]+)?/A', $this->source, $match, 0, $this->offset);
        $number = $match[0] ?? '';
        $token = $this->token(TokenKind::Number, $number);
        $this->offset += \strlen($number);

        return $token;
    }

    /** Double quotes take escapes (\n, \t, \", \\, \uXXXX); single quotes take the text as it is. */
    private function string(string $quote): Token
    {
        $startLine = $this->line;
        $startColumn = $this->column();
        ++$this->offset;
        $value = '';
        $length = \strlen($this->source);
        while (true) {
            if ($this->offset >= $length) {
                throw new SyntaxError('The string is not closed', $startLine, $startColumn);
            }
            $char = $this->source[$this->offset];
            if ($char === $quote) {
                ++$this->offset;
                break;
            }
            if ($char === "\n") {
                throw new SyntaxError('The string is not closed before the end of the line', $startLine, $startColumn);
            }
            if ($char === '\\' && $quote === '"') {
                $next = $this->source[$this->offset + 1] ?? '';
                if ($next === 'u' && preg_match('/u([0-9A-Fa-f]{4})/A', $this->source, $match, 0, $this->offset + 1) === 1) {
                    $value .= mb_chr((int) hexdec($match[1]), 'UTF-8');
                    $this->offset += 6;
                    continue;
                }
                $value .= match ($next) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    '"' => '"',
                    '\\' => '\\',
                    default => throw new SyntaxError(\sprintf('Unknown escape "\\%s" in the string', $next), $this->line, $this->column()),
                };
                $this->offset += 2;
                continue;
            }
            $value .= $char;
            ++$this->offset;
        }

        return new Token(TokenKind::Text, $value, $startLine, $startColumn);
    }

    private function punctuation(): Token
    {
        foreach (self::PUNCTUATION as $punctuation) {
            if (substr_compare($this->source, $punctuation, $this->offset, \strlen($punctuation)) === 0) {
                $token = $this->token(TokenKind::Punctuation, $punctuation);
                $this->offset += \strlen($punctuation);

                return $token;
            }
        }

        throw new SyntaxError(\sprintf('Unexpected character "%s"', $this->source[$this->offset]), $this->line, $this->column());
    }

    private function token(TokenKind $kind, string $value): Token
    {
        return new Token($kind, $value, $this->line, $this->column());
    }

    private function column(): int
    {
        return $this->offset - $this->lineStart + 1;
    }
}
