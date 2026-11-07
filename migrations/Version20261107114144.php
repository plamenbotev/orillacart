<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Extensions' records (design 56): the objects an extension declares and the indexed values its queries and
 * the fields on the shop's records use; and when each extension's schedules last ran.
 */
final class Version20261107114144 extends Migration
{
    public function getDescription(): string
    {
        return 'Extension objects, indexed values and schedule runs';
    }

    public function up(Schema $schema): void
    {
        if (!$this->hasTable('extension_objects')) {
            $this->createObjects();
        }
        if (!$this->hasColumn('packages', 'schedule_runs')) {
            $this->addSql('ALTER TABLE {prefix}packages ADD schedule_runs JSON DEFAULT \'[]\' NOT NULL');
        }
    }

    private function createObjects(): void
    {
        $this->addSql('CREATE TABLE {prefix}extension_objects (id INT AUTO_INCREMENT NOT NULL, data JSON NOT NULL, product_id INT DEFAULT NULL, order_id INT DEFAULT NULL, customer_id INT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, extension VARCHAR(40) NOT NULL, type VARCHAR(40) NOT NULL, INDEX extension_object_type (extension, type, id), INDEX extension_object_product (product_id), INDEX extension_object_order (order_id), INDEX extension_object_customer (customer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE {prefix}extension_values (id INT AUTO_INCREMENT NOT NULL, value_text VARCHAR(191) DEFAULT NULL, value_number NUMERIC(19, 4) DEFAULT NULL, value_date DATETIME DEFAULT NULL, value JSON DEFAULT NULL, extension VARCHAR(40) NOT NULL, kind VARCHAR(20) NOT NULL, owner_id INT NOT NULL, field VARCHAR(60) NOT NULL, INDEX extension_value_text (extension, kind, field, value_text), INDEX extension_value_number (extension, kind, field, value_number), INDEX extension_value_date (extension, kind, field, value_date), UNIQUE INDEX extension_value_owner (extension, kind, owner_id, field), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE {prefix}extension_objects ADD CONSTRAINT FK_621DDFDB3175A96F FOREIGN KEY (product_id) REFERENCES {prefix}products (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE {prefix}extension_objects ADD CONSTRAINT FK_48947D23051D6BDC FOREIGN KEY (order_id) REFERENCES {prefix}orders (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}extension_objects DROP FOREIGN KEY FK_621DDFDB3175A96F');
        $this->addSql('ALTER TABLE {prefix}extension_objects DROP FOREIGN KEY FK_48947D23051D6BDC');
        $this->addSql('DROP TABLE {prefix}extension_objects');
        $this->addSql('DROP TABLE {prefix}extension_values');
        $this->addSql('ALTER TABLE {prefix}packages DROP schedule_runs');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
