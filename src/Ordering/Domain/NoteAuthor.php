<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Domain;

enum NoteAuthor: string
{
    /** Written by the plugin: status changes, payments. */
    case System = 'system';
    /** Written by someone of the shop. */
    case Shop = 'shop';
    /** Written by the customer when ordering. */
    case Customer = 'customer';
}
