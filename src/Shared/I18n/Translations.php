<?php

declare(strict_types=1);

namespace OrillaCart\Shared\I18n;

use OrillaCart\Shared\Domain\Text;

/**
 * The site's language, for words the plugin's code keeps in English (rule violations, validation messages,
 * labels): translated where they leave for a person. bin/message-strings.php lists them for the .pot.
 */
interface Translations
{
    /** The words translated, the values put in after. */
    public function text(Text|string $text): string;

    /**
     * A validation message: Symfony's "{{ limit }}" parameters, and "singular|plural" chosen by the count.
     *
     * @param array<mixed> $parameters
     */
    public function message(string $template, array $parameters = [], ?int $count = null): string;
}
