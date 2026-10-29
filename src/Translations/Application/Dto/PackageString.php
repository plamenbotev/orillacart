<?php

declare(strict_types=1);

namespace OrillaCart\Translations\Application\Dto;

use OrillaCart\Shared\Domain\Text;

/**
 * One of an object's words a translation service gets to translate: the field of the translations table it
 * fills, its own value, and what translators read it as.
 */
final readonly class PackageString
{
    public function __construct(
        /** The translations table's object type: an option's words go in a package of the attribute set's. */
        public string $type,
        public int $id,
        public string $field,
        public string $value,
        /** "Name", "Pickup place Front desk: hours", translated where it is shown. */
        public Text $title,
        public bool $multiline = false,
    ) {}

    /** Which field of which object it is, the same however often it is registered. */
    public function key(): string
    {
        return "{$this->type}:{$this->id}:{$this->field}";
    }
}
