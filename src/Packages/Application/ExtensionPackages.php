<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

use OrillaCart\Packages\Application\Dto\ExtensionWord;
use OrillaCart\Packages\Domain\Package;
use OrillaCart\Packages\Domain\PackageRepository;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Translations\Application\Dto\PackageString;
use OrillaCart\Translations\Application\Dto\TranslationPackage;
use OrillaCart\Translations\Application\TranslationPackageSource;

/**
 * An extension's words for the translation service (design 57), one package per extension. A service's package
 * has one string per word, so a plural is two: the one and the many; a language with more forms is completed in
 * the shop's screen.
 */
final readonly class ExtensionPackages implements TranslationPackageSource
{
    public const string KIND = 'extension';

    public function __construct(private PackageRepository $packages, private ExtensionWords $words) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function title(): string
    {
        return 'OrillaCart extension';
    }

    public function package(int $id): ?TranslationPackage
    {
        $package = $this->find($id);
        if ($package === null) {
            return null;
        }
        $strings = [];
        foreach ($this->words->of($package) as $word) {
            $where = $word->where[0] ?? $word->text;
            if ($word->plural === null) {
                $strings[] = new PackageString(ExtensionWordTypes::TYPE, $id, ExtensionWord::field($word->key()), $word->text, Text::of('%s', $where));
                continue;
            }
            $strings[] = new PackageString(ExtensionWordTypes::TYPE, $id, ExtensionWord::field($word->key()), $word->text, Text::of('%s: one', $where));
            $strings[] = new PackageString(ExtensionWordTypes::TYPE, $id, ExtensionWord::field($word->key(), 1), $word->plural, Text::of('%s: many', $where));
        }

        return TranslationPackage::of(self::KIND, $id, $package->name(), $strings);
    }

    public function ids(): iterable
    {
        return array_map(static fn(Package $package): int => $package->id(), $this->packages->all());
    }

    private function find(int $id): ?Package
    {
        foreach ($this->packages->all() as $package) {
            if ($package->id() === $id) {
                return $package;
            }
        }

        return null;
    }
}
