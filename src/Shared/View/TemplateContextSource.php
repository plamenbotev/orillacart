<?php

declare(strict_types=1);

namespace OrillaCart\Shared\View;

/**
 * Adds to what a template sees before it renders: the scripted extensions' `template_data` (design 56), under
 * `ext.<extension>`. Tagged by the kernel.
 */
interface TemplateContextSource
{
    public const string TAG = 'orillacart.template_context';

    /**
     * @param array<string, mixed> $context as the template will see it
     *
     * @return array<string, mixed> what to add, merged under the key `ext`
     */
    public function add(string $template, array $context): array;
}
