<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Storefront;

/**
 * A site block that knows where it is, and may hold other blocks (API 28): tabs, an accordion, a section, a layout.
 * The shop renders it with renderIn() instead of render(); render() is what it shows anywhere else.
 */
interface PlacedSiteBlock extends SiteBlock
{
    /** Whether the owner places other blocks inside it: their HTML is $place->content. */
    public function inner(): bool;

    /**
     * The block's HTML, escaped by the add-on, the inner blocks' HTML printed as it is; '' shows nothing.
     *
     * @param array<string, mixed> $settings what the owner set, by key, as render() gets them
     */
    public function renderIn(array $settings, BlockPlace $place): string;

    /** The block's own editing screen, in a sandboxed frame of its sidebar; null for the sidebar's controls alone. */
    public function editor(): ?BlockEditor;
}
