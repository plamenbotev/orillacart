<?php

declare(strict_types=1);

namespace OrillaCart\Shared\WordPress;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * React code for one post type's edit screen, in the block editor or the classic one, built from
 * assets/admin/<script>/index.tsx and talking to the REST API. The EditorPanelRegistrar loads it.
 */
#[AutoconfigureTag(self::TAG)]
interface EditorPanel
{
    public const string TAG = 'orillacart.editor_panel';

    public function postType(): string;

    /** Name of the admin script entry point, built to build/admin/<script>.js. */
    public function script(): string;
}
