<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Application;

use OrillaCart\Storefront\Domain\StoreNotice;
use OrillaCart\Translations\Application\TranslatableType;

/** The store notice's text and its link's words in the site's other languages. */
final readonly class StoreNoticeTranslations implements TranslatableType
{
    public const string TYPE = 'store_notice';

    /** The shop has one notice: its translations are under this id. */
    public const int ID = 1;

    public function type(): string
    {
        return self::TYPE;
    }

    public function fields(): array
    {
        return [
            'text' => StoreNotice::MAX_TEXT_LENGTH,
            'link_label' => StoreNotice::MAX_LABEL_LENGTH,
        ];
    }
}
