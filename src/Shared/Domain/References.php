<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Domain;

/**
 * A column holding the id of a row in another area's table, kept as a plain id rather than an association so
 * the areas stay apart. The database still holds them together with a foreign key:
 *
 *     #[ORM\Column]
 *     #[References('orders')]
 *     private int $orderId,
 *
 * Without onDelete, deleting the row referenced is refused while this one exists.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class References
{
    /**
     * @param non-empty-string $table the other table, without the prefix
     * @param 'CASCADE'|'SET NULL'|null $onDelete
     */
    public function __construct(
        public string $table,
        public ?string $onDelete = null,
    ) {}
}
