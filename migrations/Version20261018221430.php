<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018221430 extends Migration
{
    public function getDescription(): string
    {
        return 'Payments, refunds and shipments held to their orders by foreign keys';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Rows of orders deleted before the keys existed would refuse them.
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        foreach (['consignments', 'refunds', 'payments'] as $table) {
            $this->addSql("DELETE FROM {prefix}{$table} WHERE order_id NOT IN (SELECT id FROM {prefix}orders)");
        }
        if (!$this->hasForeignKey('consignments', 'FK_16EFDDC67BAF2440')) {
            $this->addSql('ALTER TABLE {prefix}consignments ADD CONSTRAINT FK_16EFDDC67BAF2440 FOREIGN KEY (order_id) REFERENCES {prefix}orders (id) ON DELETE CASCADE');
        }
        if (!$this->hasForeignKey('payments', 'FK_336FAF47F469CCC4')) {
            $this->addSql('ALTER TABLE {prefix}payments ADD CONSTRAINT FK_336FAF47F469CCC4 FOREIGN KEY (order_id) REFERENCES {prefix}orders (id)');
        }
        if (!$this->hasForeignKey('refunds', 'FK_E3A3A528CFDC8998')) {
            $this->addSql('ALTER TABLE {prefix}refunds ADD CONSTRAINT FK_E3A3A528CFDC8998 FOREIGN KEY (order_id) REFERENCES {prefix}orders (id)');
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}consignments DROP FOREIGN KEY FK_16EFDDC67BAF2440');
        $this->addSql('ALTER TABLE {prefix}payments DROP FOREIGN KEY FK_336FAF47F469CCC4');
        $this->addSql('ALTER TABLE {prefix}refunds DROP FOREIGN KEY FK_E3A3A528CFDC8998');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
