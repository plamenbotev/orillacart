<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018002058 extends Migration
{
    public function getDescription(): string
    {
        return 'Shipping classes on products, and the classes a shipping rate carries';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasTable('shipping_classes')) {
            $this->addSql('CREATE TABLE {prefix}shipping_classes (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasColumn('products', 'shipping_class_id')) {
            $this->addSql('ALTER TABLE {prefix}products ADD shipping_class_id INT DEFAULT NULL');
        }
        // MySQL refuses a literal default on a JSON column: add it empty, fill it, then require it.
        if (!$this->hasColumn('shipping_rates', 'classes')) {
            $this->addSql('ALTER TABLE {prefix}shipping_rates ADD classes JSON DEFAULT NULL');
        }
        $this->addSql('UPDATE {prefix}shipping_rates SET classes = \'[]\' WHERE classes IS NULL');
        $this->addSql('ALTER TABLE {prefix}shipping_rates MODIFY classes JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE {prefix}shipping_classes');
        $this->addSql('ALTER TABLE {prefix}products DROP shipping_class_id');
        $this->addSql('ALTER TABLE {prefix}shipping_rates DROP classes');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
