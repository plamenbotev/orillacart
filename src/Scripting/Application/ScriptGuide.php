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
}
