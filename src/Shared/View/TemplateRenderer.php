<?php

declare(strict_types=1);

namespace OrillaCart\Shared\View;

/**
 * Renders a storefront template. The active theme can override any template by placing a file
 * with the same name in its orillacart/ folder. Output is escaped unless a template says otherwise.
 */
interface TemplateRenderer
{
    /**
     * @param array<string, mixed> $context view DTOs and plain values, never entities
     */
    public function render(string $template, array $context = []): string;

    /**
     * One block of a template, such as an email's subject.
     *
     * @param array<string, mixed> $context
     */
    public function renderBlock(string $template, string $block, array $context = []): string;
}
