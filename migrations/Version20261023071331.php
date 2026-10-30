<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261023071331 extends Migration
{
    public function getDescription(): string
    {
        return 'Add-ons\' marks on orders and products, for their filters';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasTable('order_marks')) {
            $this->addSql('CREATE TABLE {prefix}order_marks (id INT AUTO_INCREMENT NOT NULL, order_id INT NOT NULL, mark VARCHAR(40) NOT NULL, INDEX mark_order (mark, order_id), UNIQUE INDEX order_mark (order_id, mark), INDEX IDX_A0006B888D9F6D38 (order_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasTable('product_marks')) {
            $this->addSql('CREATE TABLE {prefix}product_marks (id INT AUTO_INCREMENT NOT NULL, product_id INT NOT NULL, mark VARCHAR(40) NOT NULL, INDEX mark_product (mark, product_id), UNIQUE INDEX product_mark (product_id, mark), INDEX IDX_8E880B284584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        }
        if (!$this->hasForeignKey('order_marks', 'FK_AFDB4BCA298AB2A2')) {
            $this->addSql('ALTER TABLE {prefix}order_marks ADD CONSTRAINT FK_AFDB4BCA298AB2A2 FOREIGN KEY (order_id) REFERENCES {prefix}orders (id) ON DELETE CASCADE');
        }
        if (!$this->hasForeignKey('product_marks', 'FK_512002FD1345E553')) {
            $this->addSql('ALTER TABLE {prefix}product_marks ADD CONSTRAINT FK_512002FD1345E553 FOREIGN KEY (product_id) REFERENCES {prefix}products (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}order_marks DROP FOREIGN KEY FK_AFDB4BCA298AB2A2');
        $this->addSql('ALTER TABLE {prefix}product_marks DROP FOREIGN KEY FK_512002FD1345E553');
        $this->addSql('DROP TABLE {prefix}order_marks');
        $this->addSql('DROP TABLE {prefix}product_marks');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
