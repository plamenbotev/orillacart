<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261017232751 extends Migration
{
    public function getDescription(): string
    {
        return 'Variations share their id with a post of their own, and may weigh and measure differently from their product';
    }

    public function up(Schema $schema): void
    {
        // Before the first release: variations made before had no post to take their id from, so they go,
        // with their stock.
        $this->addSql("DELETE FROM {prefix}stock_levels WHERE item_kind = 'variation'");
        $this->addSql('DELETE FROM {prefix}variations');
        $this->addSql('ALTER TABLE {prefix}variations ADD weight NUMERIC(12, 4) DEFAULT NULL, ADD length NUMERIC(12, 4) DEFAULT NULL, ADD width NUMERIC(12, 4) DEFAULT NULL, ADD height NUMERIC(12, 4) DEFAULT NULL, CHANGE id id INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}variations DROP weight, DROP length, DROP width, DROP height, CHANGE id id INT AUTO_INCREMENT NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
