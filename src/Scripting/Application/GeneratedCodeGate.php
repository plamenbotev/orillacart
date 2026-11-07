<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application;

use OrillaCart\Scripting\Domain\Catchable;
use OrillaCart\Scripting\Domain\Ops;

/**
 * The hard gate on generated code: before a compiled class is kept, included or evaluated, its source is read
 * with PHP's own tokenizer and refused unless every token is one the compiler is known to make. The compiler
 * emits a narrow grammar (one class extending CompiledScript, methods named `fn_*`/`m_*`, variables `$v_*`,
 * single-quoted strings, static calls on Ops, calls on `$this->c`) and nothing else may pass: no other class
 * or function, no `new`, `eval`, `include`, backtick, interpolated string, variable variable or inline HTML. An
 * escaping bug in the compiler, or a file planted in the cache folder, then stops here instead of running.
 *
 * Checked after compiling and again before loading, so the sandbox never rests on the compiler alone.
 */
final class GeneratedCodeGate
{
    private const array CLASSES = [
        '\\' . CompiledScript::class => T_EXTENDS,
        '\\' . Ops::class => T_DOUBLE_COLON,
        '\\' . Catchable::class => T_CATCH,
    ];
    private const array OPS_METHODS = ['truthy', 'get', 'assign', 'eq', 'cmp', 'add', 'sub', 'mul', 'div', 'intdiv', 'mod', 'neg', 'concat', 'in', 'startsWith', 'endsWith', 'range', 'iterable', 'invoke', 'key', 'failure', 'filter', 'test'];
    private const array CONTEXT_METHODS = ['step', 'bytes', 'call'];
    private const array WORDS = ['strict_types', 'NULL', 'null', 'true', 'false'];
    private const array PUNCTUATION = ['(', ')', '=', ';', '{', '}', ':', '[', ']', ',', '>', '<', '!', '?'];
    private const array KINDS = [
        T_WHITESPACE, T_DECLARE, T_NAMESPACE, T_FINAL, T_CLASS, T_EXTENDS, T_PUBLIC, T_STATIC, T_FUNCTION, T_ARRAY,
        T_RETURN, T_LNUMBER, T_DNUMBER, T_DOUBLE_ARROW, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_IS_GREATER_OR_EQUAL,
        T_IS_SMALLER_OR_EQUAL, T_IS_EQUAL, T_IS_NOT_EQUAL, T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_BOOLEAN_AND,
        T_BOOLEAN_OR, T_COALESCE, T_WHILE, T_IF, T_ELSE, T_ELSEIF, T_CONTINUE, T_BREAK, T_FOREACH, T_AS, T_USE,
        T_ELLIPSIS, T_TRY, T_THROW, T_CATCH,
    ];
    private const string COMMENT = '/** Generated from a script package: do not edit. */';

    /**
     * Why the source is refused, or null when every token is one the compiler makes.
     *
     * The tokens are PHP's own, so what passes is what PHP would run, not what a regular expression thinks it
     * sees. Only the first fault is reported; a refusal is a bug or an intrusion, not something to fix in place.
     */
    public static function refuse(string $source): ?string
    {
        if (!str_starts_with($source, "<?php\n")) {
            return 'the source does not start with the PHP open tag';
        }
        $tokens = array_values(\PhpToken::tokenize($source));
        $previous = null;
        $declared = 0;
        foreach ($tokens as $index => $token) {
            if ($token->is(T_WHITESPACE)) {
                continue;
            }
            $fault = self::fault($token, $previous, $index, $tokens);
            if ($fault !== null) {
                return \sprintf('%s at line %d', $fault, $token->line);
            }
            if ($token->is(T_CLASS)) {
                ++$declared;
            }
            $previous = $token;
        }
        if ($declared !== 1) {
            return \sprintf('%d classes declared, not one', $declared);
        }

        return null;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private static function fault(\PhpToken $token, ?\PhpToken $previous, int $index, array $tokens): ?string
    {
        $text = $token->text;
        if ($token->is(T_OPEN_TAG)) {
            return $index === 0 ? null : 'a second open tag';
        }
        if ($token->is(T_DOC_COMMENT)) {
            return $text === self::COMMENT ? null : 'a comment the compiler does not write';
        }
        if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
            return $text[0] === "'" ? null : 'a double-quoted string';
        }
        if ($token->is(T_VARIABLE)) {
            return preg_match('/^\$(this|v_[A-Za-z0-9_]+|t_\d+|e_\d+)$/', $text) === 1 ? null : \sprintf('the variable %s', $text);
        }
        if ($token->is(T_NAME_QUALIFIED)) {
            return $text === Compiler::NAMESPACE && $previous?->is(T_NAMESPACE) === true ? null : \sprintf('the name %s', $text);
        }
        if ($token->is(T_NAME_FULLY_QUALIFIED)) {
            $where = self::CLASSES[$text] ?? null;
            if ($where === null) {
                return \sprintf('the class %s', $text);
            }
            // CompiledScript only after `extends`, Catchable only after `catch (`, Ops only before `::`.
            $neighbour = $where === T_DOUBLE_COLON ? self::next($tokens, $index) : ($where === T_CATCH ? self::before($tokens, $index, 2) : $previous);
            return $neighbour?->is($where) === true ? null : \sprintf('%s out of place', $text);
        }
        if ($token->is(T_OBJECT_OPERATOR)) {
            // Only `$this->`, `$this->c->` and the caught error's `$e_N->value()`.
            if ($previous?->is(T_VARIABLE) === true && (preg_match('/^\$(this|e_\d+)$/', $previous->text) === 1)) {
                return null;
            }

            return $previous?->text === 'c' ? null : 'a method call on a value';
        }
        if ($token->is(T_STRING)) {
            return self::word($text, $previous);
        }
        if ($token->is(T_DOUBLE_COLON)) {
            return $previous?->text === '\\' . Ops::class ? null : 'a static call not on Ops';
        }
        if ($token->is(T_CLASS)) {
            return $previous?->is(T_FINAL) === true ? null : 'the class keyword out of place';
        }
        if ($token->is(self::KINDS)) {
            return null;
        }
        if ($token->id === \ord($text[0]) && \strlen($text) === 1 && \in_array($text, self::PUNCTUATION, true)) {
            return null;
        }

        return \sprintf('the token %s (%s)', $token->getTokenName(), $text);
    }

    private static function word(string $text, ?\PhpToken $previous): ?string
    {
        if (\in_array($text, self::WORDS, true)) {
            return null;
        }
        if ($previous?->is(T_CLASS) === true) {
            return preg_match('/^Script_[A-Za-z0-9_]+$/', $text) === 1 ? null : \sprintf('the class name %s', $text);
        }
        if ($previous?->is(T_FUNCTION) === true) {
            return $text === 'manifest' || preg_match('/^(fn_[A-Za-z0-9_]+|m_\d+)$/', $text) === 1 ? null : \sprintf('the method name %s', $text);
        }
        if ($previous?->is(T_DOUBLE_COLON) === true) {
            return \in_array($text, self::OPS_METHODS, true) ? null : \sprintf('Ops::%s', $text);
        }
        if ($previous?->is(T_OBJECT_OPERATOR) === true) {
            $allowed = $text === 'c' || $text === 'value' || \in_array($text, self::CONTEXT_METHODS, true) || preg_match('/^(fn_[A-Za-z0-9_]+|m_\d+)$/', $text) === 1;

            return $allowed ? null : \sprintf('the member %s', $text);
        }

        return \sprintf('the word %s', $text);
    }

    /** @param list<\PhpToken> $tokens */
    private static function next(array $tokens, int $index): ?\PhpToken
    {
        for ($i = $index + 1; isset($tokens[$i]); ++$i) {
            if (!$tokens[$i]->is(T_WHITESPACE)) {
                return $tokens[$i];
            }
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private static function before(array $tokens, int $index, int $steps): ?\PhpToken
    {
        for ($i = $index - 1; $i >= 0; --$i) {
            if (!$tokens[$i]->is(T_WHITESPACE) && --$steps === 0) {
                return $tokens[$i];
            }
        }

        return null;
    }
}
