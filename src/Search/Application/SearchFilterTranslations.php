<?php

declare(strict_types=1);

namespace OrillaCart\Search\Application;

use OrillaCart\Search\Domain\SearchFilter;
use OrillaCart\Translations\Application\TranslatableType;

/** The labels of the shop's search filters in the site's other languages. */
final readonly class SearchFilterTranslations implements TranslatableType
{
    public const string TYPE = 'search_filter';

    public function type(): string
    {
        return self::TYPE;
    }

    public function fields(): array
    {
        return ['label' => SearchFilter::MAX_LABEL_LENGTH];
    }
}
