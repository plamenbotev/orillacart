<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261023075434 extends Migration
{
    public function getDescription(): string
    {
        return 'Orders hold their money as lines (items, shipping, fees) with taxes per rate and jurisdiction and price adjustments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE {prefix}order_adjustments (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(20) NOT NULL, source VARCHAR(100) NOT NULL, label VARCHAR(200) NOT NULL, amount NUMERIC(19, 4) NOT NULL, scope VARCHAR(10) NOT NULL, free_shipping TINYINT NOT NULL, reason VARCHAR(200) DEFAULT NULL, line_id INT NOT NULL, INDEX IDX_DB5E379D4D7B7542 (line_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE {prefix}order_taxes (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(64) NOT NULL, percent NUMERIC(7, 4) NOT NULL, compound TINYINT NOT NULL, country CHAR(2) NOT NULL, region VARCHAR(3) DEFAULT NULL, level VARCHAR(10) NOT NULL, code VARCHAR(60) NOT NULL, jurisdiction VARCHAR(100) NOT NULL, taxable NUMERIC(19, 4) NOT NULL, amount NUMERIC(19, 4) NOT NULL, refunded NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, line_id INT NOT NULL, INDEX order_tax_jurisdiction (country, region, code), INDEX IDX_5EE436234D7B7542 (line_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE {prefix}order_adjustments ADD CONSTRAINT FK_DB5E379D4D7B7542 FOREIGN KEY (line_id) REFERENCES {prefix}order_lines (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE {prefix}order_taxes ADD CONSTRAINT FK_5EE436234D7B7542 FOREIGN KEY (line_id) REFERENCES {prefix}order_lines (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE {prefix}order_lines ADD kind VARCHAR(10) DEFAULT \'item\' NOT NULL, DROP tax, DROP discount, CHANGE product_id product_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE {prefix}orders ADD tax_exemption JSON DEFAULT NULL, ADD tax_provider VARCHAR(40) DEFAULT NULL, ADD tax_reference VARCHAR(100) DEFAULT NULL, DROP shipping_total, DROP shipping_tax, DROP taxes, DROP subtotal, DROP items_tax, DROP shipping_refunded, DROP discount_total, DROP discounts, DROP fees, DROP fees_total, DROP fees_tax');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}order_adjustments DROP FOREIGN KEY FK_DB5E379D4D7B7542');
        $this->addSql('ALTER TABLE {prefix}order_taxes DROP FOREIGN KEY FK_5EE436234D7B7542');
        $this->addSql('DROP TABLE {prefix}order_adjustments');
        $this->addSql('DROP TABLE {prefix}order_taxes');
        $this->addSql('ALTER TABLE {prefix}orders ADD shipping_total NUMERIC(19, 4) NOT NULL, ADD shipping_tax NUMERIC(19, 4) NOT NULL, ADD taxes JSON NOT NULL, ADD subtotal NUMERIC(19, 4) NOT NULL, ADD items_tax NUMERIC(19, 4) NOT NULL, ADD shipping_refunded TINYINT DEFAULT 0 NOT NULL, ADD discount_total NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, ADD discounts JSON NOT NULL, ADD fees JSON NOT NULL, ADD fees_total NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, ADD fees_tax NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, DROP tax_exemption, DROP tax_provider, DROP tax_reference');
        $this->addSql('ALTER TABLE {prefix}order_lines ADD tax NUMERIC(19, 4) NOT NULL, ADD discount NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, DROP kind, CHANGE product_id product_id INT NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
