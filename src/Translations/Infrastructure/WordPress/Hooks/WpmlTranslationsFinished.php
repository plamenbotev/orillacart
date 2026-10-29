<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Infrastructure\WordPress\Hooks;

use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Translations\Application\SyncTranslationPackages;

/**
 * WPML's translation management finished or saved a translation job: what it brings back is taken into the
 * shop's translations, in the background.
 */
#[AsHook('wpml_pro_translation_completed', method: 'finished')]
#[AsHook('wpml_translation_job_saved', method: 'finished')]
final readonly class WpmlTranslationsFinished
{
    public function __construct(private SyncTranslationPackages $sync) {}

    public function finished(): void
    {
        $this->sync->pull();
    }
}
