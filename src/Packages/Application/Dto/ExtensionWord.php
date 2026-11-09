<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

/**
 * One of an extension's words to translate (design 57): what it says, its plural when it has one, its context
 * as `_x()` gives it, and the places it stands. The same words in the same context are one word wherever they
 * are, as in gettext.
 */
final readonly class ExtensionWord
{
    /** The translations table's field of a word's first form; `word.<key>-<n>` holds its other plural forms. */
    public const string FIELD = 'word.';

    /**
     * @param list<string> $where `main.ocs, line 12`, `settings: merchant_id, label`
     */
    public function __construct(
        public string $text,
        public ?string $plural = null,
        public string $context = '',
        public array $where = [],
    ) {}

    public function key(): string
    {
        return self::keyOf($this->text, $this->plural, $this->context);
    }

    /** 16 hex characters of the SHA-256 of the context and the words, so a key is a word wherever it is. */
    public static function keyOf(string $text, ?string $plural = null, string $context = ''): string
    {
        return substr(hash('sha256', $context . "\x04" . $text . ($plural === null ? '' : "\x00" . $plural)), 0, 16);
    }

    /** The field of one of its forms in the translations table. */
    public static function field(string $key, int $form = 0): string
    {
        return self::FIELD . $key . ($form === 0 ? '' : '-' . $form);
    }

    /** The same word, also standing in these places. */
    public function alsoAt(string ...$where): self
    {
        return new self($this->text, $this->plural, $this->context, array_values(array_unique([...$this->where, ...$where])));
    }
}
