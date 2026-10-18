<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Doctrine;

use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use OrillaCart\Shared\Domain\References;

/**
 * Adds the foreign keys that #[References] columns ask for to the schema the mapping describes, so the migration
 * diff creates them and keeps them. Their names follow Doctrine's FK_ and hex form, which Migration renames for
 * each site.
 */
final readonly class ReferencesListener
{
    public function __construct(private string $prefix) {}

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();
        foreach ($args->getEntityManager()->getMetadataFactory()->getAllMetadata() as $metadata) {
            $table = $metadata->getTableName();
            if ($table === '' || !$schema->hasTable($table)) {
                continue;
            }
            foreach ($metadata->getReflectionClass()->getProperties() as $property) {
                foreach ($property->getAttributes(References::class) as $attribute) {
                    $references = $attribute->newInstance();
                    $column = $metadata->getColumnName($property->getName());
                    $schema = $schema->edit()->modifyTableByUnquotedName(
                        $table,
                        function (TableEditor $editor) use ($table, $column, $references): void {
                            $editor->addForeignKeyConstraint(new ForeignKeyConstraint(
                                [$column],
                                $this->prefix . $references->table,
                                ['id'],
                                'FK_' . strtoupper(substr(md5("{$table}.{$column}"), 0, 16)),
                                $references->onDelete === null ? [] : ['onDelete' => $references->onDelete],
                            ));
                        },
                    )->create();
                }
            }
        }
        $args->setSchema($schema);
    }
}
