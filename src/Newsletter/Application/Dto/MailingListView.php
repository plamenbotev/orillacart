<?php

declare(strict_types=1);

namespace OrillaCart\Newsletter\Application\Dto;

/** A mailing list an add-on brings, and whether the shop's sign-ups go to it. */
final readonly class MailingListView
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $on,
    ) {}
}
