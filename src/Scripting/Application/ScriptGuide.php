<?php

declare(strict_types=1);

namespace OrillaCart\Scripting\Application;

/**
 * The written guide to extension scripts (docs/extension-scripts-guide.md), as the assistant and the Extensions
 * screen hand it to whoever writes an extension; the reference (ScriptReference) is the generated list beside it.
 */
interface ScriptGuide
{
    /** The guide as Markdown; '' when the install carries none. */
    public function markdown(): string;

    /** The shop's REST API, documented, as Markdown (docs/rest-api.md); '' when the install carries none. */
    public function restApi(): string;

    /**
     * The shop's storefront templates with what each gets, from the note at the top of each: for template_data,
     * overrides and slot templates.
     *
     * @return array<string, string> template name ("storefront/account.html.twig") → its note
     */
    public function templateNotes(): array;

    /**
     * What an extension's templates may use: the sandbox's tags, filters, functions and tests.
     *
     * @return array<string, list<string>>
     */
    public function templateSandbox(): array;
}
