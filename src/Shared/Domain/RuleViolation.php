<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Domain;

/**
 * A business rule refused the change. Each area extends this for its own rules;
 * the message is written for the person who asked for the change.
 *
 * The message is an English template and the values that go into it ("%s is out of stock." and the name), so it
 * is translated where it is shown (text()); getMessage() is the English sentence, for logs.
 */
class RuleViolation extends \DomainException
{
    public readonly Text $text;

    /**
     * @param string|Text                         $message English, with sprintf() placeholders for the values; or
     *                                                     another violation's words, passed on
     * @param list<int|float|string|\Stringable> $values
     */
    public function __construct(
        string|Text $message,
        public readonly string $errorCode = 'orillacart_rule_violation',
        ?\Throwable $previous = null,
        array $values = [],
    ) {
        $this->text = $message instanceof Text ? $message : Text::of($message, ...$values);
        parent::__construct((string) $this->text, 0, $previous);
    }
}
