<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Infrastructure;

use OrillaCart\Translations\Application\TranslationPackageSource;
use OrillaCart\Translations\Application\TranslationPackageSources;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Every area's package sources, as the container found them by their tag. */
final readonly class TaggedTranslationPackageSources implements TranslationPackageSources
{
    /**
     * @param iterable<TranslationPackageSource> $sources
     */
    public function __construct(
        #[AutowireIterator(TranslationPackageSource::TAG)]
        private iterable $sources,
    ) {}

    public function all(): array
    {
        return array_values([...$this->sources]);
    }

    public function get(string $kind): ?TranslationPackageSource
    {
        foreach ($this->sources as $source) {
            if ($source->kind() === $kind) {
                return $source;
            }
        }

        return null;
    }
}
