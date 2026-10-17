<?php

declare(strict_types=1);

namespace OrillaCart\Shared\WordPress;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One of the shop's own admin screens: a menu entry under "Shop" whose page is a React app
 * built from assets/admin/<script>/index.tsx and talking to the REST API.
 * The AdminPageRegistrar adds the menu entry, prints the mount element and loads the script.
 */
#[AutoconfigureTag(self::TAG)]
interface AdminPage
{
    public const string TAG = 'orillacart.admin_page';

    /** Unique page slug; the mount element id is "orillacart-admin-{slug}". */
    public function slug(): string;

    /** Translated title for the menu entry and the browser tab. */
    public function title(): string;

    /** The capability needed to see the screen; its REST routes check their own. */
    public function capability(): string;

    /** Name of the admin script entry point, built to build/admin/<script>.js. */
    public function script(): string;

    /** Position in the Shop menu; the lowest opens when the top-level entry is clicked. */
    public function menuOrder(): int;
}
