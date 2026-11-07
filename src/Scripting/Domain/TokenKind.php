<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Domain;

/** What a token is. Words are keywords only where the parser expects one: `in`, `is`, `and` are words too. */
enum TokenKind: string
{
    case Word = 'word';
    case Number = 'number';
    case Text = 'string';
    case Punctuation = 'punctuation';
    case Newline = 'newline';
    case End = 'end';
}
