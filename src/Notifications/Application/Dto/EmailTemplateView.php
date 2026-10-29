<?php

declare(strict_types=1);

namespace OrillaCart\Notifications\Application\Dto;

/**
 * One of the email templates, as the editor lists them.
 *
 * @param string      $name     as the files name it, without emails/ and .html.twig: "order-shipped", "layout"
 * @param string      $previews the email it shows in a preview: an email kind (order_shipped), or account_confirm
 *                              and account_exists; layout and parts show in order_processing
 * @param string|null $updatedAt when the shop last saved its own; null while the file gives it
 */
final readonly class EmailTemplateView
{
    public function __construct(
        public string $name,
        public bool $edited,
        public string $previews,
        public ?string $updatedAt = null,
        /** @var list<string> the locales that have a template of their own, in place of this one */
        public array $languages = [],
    ) {}
}
