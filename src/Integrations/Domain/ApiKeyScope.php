<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Domain;

/**
 * What an API key lets an integration do, as the shop manager who made it may.
 */
enum ApiKeyScope: string
{
    /** Read only: reports, orders, products, but change nothing. */
    case Read = 'read';
    case ReadWrite = 'read_write';
}
