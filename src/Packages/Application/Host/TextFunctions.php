<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Host;

use OrillaCart\Packages\Application\ExtensionTranslations;
use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\Host\HostNamespace;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Scripting\Domain\ScriptError;

/**
 * The words an extension shows, in the language the shop shows them in (design 57): `__`, `_x`, `_n` and `_nx`,
 * as WordPress and the shop's templates name them, and `language()`. The static check makes sure the words are
 * written in the call, so they can be listed for translators.
 */
final readonly class TextFunctions implements HostNamespace
{
    public function __construct(private ExtensionTranslations $translations) {}

    public function functions(): array
    {
        // Each only works a value out: none changes the shop or reaches outside it.
        return HostFunction::reading([
            new HostFunction('__', fn(Context $c, mixed $text): string => $this->translations->word($c->grant->extension, Ops::text($text)), 1, 1, '__("words"): the words in the language shown, as the extension\'s translations or the shop\'s give them; written as a string, values put in after (|replace)'),
            new HostFunction('_x', fn(Context $c, mixed $text, mixed $context): string => $this->translations->word($c->grant->extension, Ops::text($text), Ops::text($context)), 2, 2, '_x("words", "context"): as __(), told apart from the same words meaning something else by the context'),
            new HostFunction('_n', fn(Context $c, mixed $single, mixed $plural, mixed $count): string => $this->translations->plural($c->grant->extension, Ops::text($single), Ops::text($plural), self::count($count)), 3, 3, '_n("one", "many", count): the form the count takes in the language shown'),
            new HostFunction('_nx', fn(Context $c, mixed $single, mixed $plural, mixed $count, mixed $context): string => $this->translations->plural($c->grant->extension, Ops::text($single), Ops::text($plural), self::count($count), Ops::text($context)), 4, 4, '_nx("one", "many", count, "context"): as _n(), with a context'),
            new HostFunction('language', fn(Context $c): string => $this->translations->locale(), 0, 0, 'language(): the locale words are shown in, as "de_DE"'),
        ]);
    }

    private static function count(mixed $count): int
    {
        if (\is_int($count) || \is_float($count) || (\is_string($count) && is_numeric($count))) {
            return (int) floor(abs((float) $count));
        }

        throw new ScriptError(\sprintf('The count of _n() is a number, not %s', Ops::text($count) === '' ? 'nothing' : '"' . Ops::text($count) . '"'));
    }
}
