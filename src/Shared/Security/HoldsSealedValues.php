<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Security;

/**
 * Keeps values sealed with Secrets somewhere other than an entity's column (an option), so `wp orillacart doctor`
 * finds them: those sealed with an earlier key are sealed again with the current one. Implementations are found
 * by the container through this tag (the Kernel gives it: this directory is not scanned for services).
 */
interface HoldsSealedValues
{
    public const string SEALED_VALUES_TAG = 'orillacart.sealed_values';

    /**
     * @return list<string> the values as kept: sealed, or never sealed; none that is empty
     */
    public function sealedValues(): array;

    /** Keeps them again, sealed with the current key: asked only once every one of them opens. */
    public function resealValues(): void;
}
