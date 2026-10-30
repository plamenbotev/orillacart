<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261020100703 extends Migration
{
    public function getDescription(): string
    {
        return 'Days, and times of day, customers choose for delivery';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('carriers', 'delivery_schedule')) {
            $this->addSql('ALTER TABLE {prefix}carriers ADD delivery_schedule JSON DEFAULT NULL');
        }
        if (!$this->hasColumn('orders', 'delivery_date')) {
            $this->addSql('ALTER TABLE {prefix}orders ADD delivery_date DATE DEFAULT NULL, ADD delivery_slot VARCHAR(40) DEFAULT NULL, ADD delivery_key VARCHAR(60) DEFAULT NULL');
        }
        if (!$this->hasIndex('orders', 'order_delivery')) {
            $this->addSql('CREATE INDEX order_delivery ON {prefix}orders (delivery_key, delivery_date)');
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}carriers DROP delivery_schedule');
        $this->addSql('DROP INDEX order_delivery ON {prefix}orders');
        $this->addSql('ALTER TABLE {prefix}orders DROP delivery_date, DROP delivery_slot, DROP delivery_key');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
