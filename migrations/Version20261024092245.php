<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * "Price: high to low" sorts by the highest price, as a product with variations goes up to.
 */
final class Version20261024092245 extends Migration
{
    public function getDescription(): string
    {
        return 'Search index sorted by the highest price';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX search_price_max ON {prefix}search_documents (price_max)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX search_price_max ON {prefix}search_documents');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
