<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Domain\OptionDraft;
use OrillaCart\Catalog\Domain\ProductRepository;
use OrillaCart\Translations\Application\TranslatableType;

/** The names of attributes' options in the site's other languages. */
final readonly class OptionTranslations implements TranslatableType
{
    public const string TYPE = 'attribute_option';

    public function __construct(private ProductRepository $products) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function fields(): array
    {
        return ['name' => OptionDraft::MAX_NAME_LENGTH];
    }

    public function existing(array $ids): array
    {
        return $this->products->optionIdsAmong($ids);
    }
}
