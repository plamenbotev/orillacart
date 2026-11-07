<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain;

final readonly class Token
{
    public function __construct(
        public TokenKind $kind,
        /** The word, the number's text, the string's value (unescaped) or the punctuation. */
        public string $value,
        public int $line,
        public int $column,
    ) {}

    public function is(TokenKind $kind, ?string $value = null): bool
    {
        return $this->kind === $kind && ($value === null || $this->value === $value);
    }

    /** For messages: `"while"`, `a number`, `the end of the file`. */
    public function describe(): string
    {
        return match ($this->kind) {
            TokenKind::Word, TokenKind::Punctuation => '"' . $this->value . '"',
            TokenKind::Number => 'a number',
            TokenKind::Text => 'a string',
            TokenKind::Newline => 'the end of the line',
            TokenKind::End => 'the end of the file',
        };
    }
}
