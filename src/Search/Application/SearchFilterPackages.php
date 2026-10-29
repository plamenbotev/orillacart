<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Search\Domain\SearchFilter;
use OrillaCart\Search\Domain\SearchFilterRepository;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Translations\Application\Dto\PackageString;
use OrillaCart\Translations\Application\Dto\TranslationPackage;
use OrillaCart\Translations\Application\TranslationPackageSource;

/** A shop filter's label for the translation service. */
final readonly class SearchFilterPackages implements TranslationPackageSource
{
    public const string KIND = 'search_filter';

    public function __construct(private SearchFilterRepository $filters) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function title(): string
    {
        return 'OrillaCart filter';
    }

    public function package(int $id): ?TranslationPackage
    {
        $filter = $this->filters->find($id);

        return $filter === null ? null : TranslationPackage::of(self::KIND, $id, $filter->label(), [new PackageString(SearchFilterTranslations::TYPE, $id, 'label', $filter->label(), Text::of('Label'))]);
    }

    public function ids(): iterable
    {
        return array_map(static fn(SearchFilter $filter): int => $filter->id(), $this->filters->all());
    }
}
