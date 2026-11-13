<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Storefront;

/**
 * A layout of blocks the editor offers to insert (API 28): a hero, a features grid, a page's start. What it holds
 * is cleaned as an extension's template prints (no script, no handlers), and its blocks' attributes hold no HTML:
 * inserting it writes it into the post.
 *
 *     $registry->blockPattern(new class implements BlockPattern { … });
 */
interface BlockPattern
{
    /** Lowercase letters, digits and underscores, starting with the add-on's own prefix: "acme_hero". */
    public function id(): string;

    /** Its name among the patterns: "Hero with a button". */
    public function title(): string;

    public function description(): string;

    /**
     * The pattern categories it is listed in: WordPress's (banner, buttons, columns, featured, footer, gallery,
     * header, text, …) or "shop".
     *
     * @return list<string>
     */
    public function categories(): array;

    /** The block markup: <!-- wp:heading --><h2>…</h2><!-- /wp:heading -->. */
    public function content(): string;
}
