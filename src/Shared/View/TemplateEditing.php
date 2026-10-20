<?php

declare(strict_types=1);

namespace OrillaCart\Shared\View;

/**
 * What the email template editor needs from the template engine: the files the shop starts from, a check that an
 * edit runs, and rendering as edits not saved yet would.
 */
interface TemplateEditing
{
    /**
     * The email templates there are files of, the theme's or the plugin's.
     *
     * @return list<string> names such as "emails/order-shipped.html.twig", by name
     */
    public function names(): array;

    /** The template as the theme's or the plugin's file has it, the shop's edit aside; null when there is none. */
    public function fileSource(string $name): ?string;

    /**
     * @throws TemplateRejected for an edit that does not compile, or uses what the sandbox does not allow
     */
    public function check(string $name, string $source): void;

    /**
     * Runs the work with these templates' sources in place of what is saved, rendering strictly: a variable a
     * template was not given fails, as the shop wants to know before it saves.
     *
     * @template T
     *
     * @param array<string, string> $drafts sources by template name
     * @param \Closure(): T         $work
     *
     * @return T
     */
    public function previewing(array $drafts, \Closure $work): mixed;
}
