<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application\Dto;

use OrillaCart\Ordering\Domain\OrderNote;

final readonly class OrderNoteView
{
    public function __construct(
        public string $text,
        public string $author,
        public bool $forCustomer,
        public string $createdAt,
        public ?int $id = null,
    ) {}

    public static function fromNote(OrderNote $note): self
    {
        return new self($note->text(), $note->author()->value, $note->isForCustomer(), $note->createdAt()->format(\DATE_ATOM), $note->id());
    }
}
