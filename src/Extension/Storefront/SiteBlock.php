<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Storefront;

use OrillaCart\Extension\Setting;

/**
 * A block an add-on gives the whole site, beside its product blocks (ProductBlock): a banner, a newsletter form, a
 * store locator. The shop registers it as "orillacart-addon/{id}" (underscores become dashes); the owner places it
 * anywhere the editor or the Site Editor takes blocks, and fills its settings in the block's sidebar. Since
 * version 24.
 *
 *     $registry->siteBlock(new class implements SiteBlock { … });
 */
interface SiteBlock
{
    /** Lowercase letters, digits and underscores, up to 40, starting with the add-on's own prefix: "acme_banner". */
    public function id(): string;

    /** The block's name in the inserter: "Banner". */
    public function title(): string;

    /** What it shows, in the inserter. */
    public function description(): string;

    /**
     * What the owner sets in the block's sidebar: text, number, boolean and choice settings (a secret is not one).
     *
     * @return list<Setting>
     */
    public function settings(): array;

    /**
     * The block's HTML, escaped by the add-on; an empty string shows nothing.
     *
     * @param array<string, mixed> $settings what the owner set, by key; one never set is missing: a string, a number or
     *                                     a boolean, and for a block's own kinds (API 28) an image's map, an id, or a
     *                                     list of maps
     */
    public function render(array $settings): string;
}
