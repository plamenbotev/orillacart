<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Storefront;

/**
 * Renders one of the storefront's templates as the shop does: an extension's override, an untrusted folder's
 * (UntrustedTemplateFolders), the theme's, then the shop's own, each with the storefront's functions and filters;
 * those not trusted with PHP in the sandbox. For an add-on that places templated markup itself: a block of a
 * theme's, a section. Since version 24.
 */
interface Templates
{
    /**
     * The template's HTML.
     *
     * @param string               $name    "storefront/account.html.twig", "sections/hero.twig"
     * @param array<string, mixed> $context its variables: scalars, arrays and the shop's view objects
     *
     * @throws TemplateFailed when there is no such template, or it does what the sandbox refuses
     */
    public function render(string $name, array $context = []): string;

    /** Whether a template of that name is found. */
    public function exists(string $name): bool;
}
