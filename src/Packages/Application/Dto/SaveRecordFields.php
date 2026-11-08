<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Dto;

/** The values of an extension's fields on a product, an order or a customer, as the admin saves them. */
final readonly class SaveRecordFields
{
    /**
     * @param array<string, mixed> $fields by field name; null clears a field
     */
    public function __construct(public array $fields = []) {}
}
