<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;

/**
 * Prefixes every mapped table, so entities name their tables without the WordPress prefix
 * (#[ORM\Table(name: 'orders')] becomes wp_orillacart_orders).
 */
final readonly class TablePrefixListener
{
    public function __construct(private string $prefix) {}

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();

        if ($metadata->isInheritanceTypeSingleTable() && !$metadata->isRootEntity()) {
            return;
        }

        if (!str_starts_with($metadata->getTableName(), $this->prefix)) {
            $metadata->setPrimaryTable(['name' => $this->prefix . $metadata->getTableName()]);
        }

        foreach ($metadata->associationMappings as $mapping) {
            if ($mapping instanceof ManyToManyOwningSideMapping && !str_starts_with($mapping->joinTable->name, $this->prefix)) {
                $mapping->joinTable->name = $this->prefix . $mapping->joinTable->name;
            }
        }
    }
}
