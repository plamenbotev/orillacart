<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Domain;

interface LegalSettingsRepository
{
    public function load(): LegalSettings;

    public function save(LegalSettings $settings): void;

    /** Whether the id is a page the shop can link, published or not yet. */
    public function isPage(int $id): bool;

    /**
     * Makes a page of the site, a draft for the owner to check or published.
     *
     * @return int its id
     */
    public function makePage(string $title, string $content, bool $publish): int;

    /**
     * A published page's title and its content as HTML, for an email; null for one not published.
     *
     * @return array{title: string, html: string}|null
     */
    public function published(int $id): ?array;
}
