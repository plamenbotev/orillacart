<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application\Dto;

use OrillaCart\Customers\Domain\CustomerNote;

final readonly class CustomerNoteView
{
    public function __construct(
        public int $id,
        public string $text,
        /** Who of the shop wrote it; null when their account is gone. */
        public ?string $author,
        public string $createdAt,
    ) {}

    public static function fromNote(CustomerNote $note, ?string $author): self
    {
        return new self($note->id(), $note->text(), $author, $note->createdAt()->format(\DATE_ATOM));
    }
}
