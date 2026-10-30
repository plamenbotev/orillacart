<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Back in stock: how often the mail system refused a request's email, so one address it keeps refusing is let go
 * rather than holding up everyone else waiting for the item.
 */
final class Version20261030140000 extends Migration
{
    public function getDescription(): string
    {
        return 'How often a back in stock email was refused, so a recipient the mail system keeps refusing is let go';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off goes on when it runs again.
        if (!$this->hasColumn('back_in_stock', 'refused')) {
            $this->addSql('ALTER TABLE {prefix}back_in_stock ADD refused SMALLINT DEFAULT 0 NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}back_in_stock DROP refused');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
