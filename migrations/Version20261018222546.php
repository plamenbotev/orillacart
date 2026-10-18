<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261018222546 extends Migration
{
    public function getDescription(): string
    {
        return 'Refunds keep the tax they gave back; orders keep what was paid';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Invoices of orders deleted before the key existed would refuse it.
        $this->addSql('DELETE FROM {prefix}invoices WHERE order_id NOT IN (SELECT id FROM {prefix}orders)');
        // JSON columns take no default everywhere: added empty, filled, then required.
        $this->addSql('ALTER TABLE {prefix}invoices ADD taxes JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE {prefix}invoices ADD CONSTRAINT FK_5D0F1A57890A586E FOREIGN KEY (order_id) REFERENCES {prefix}orders (id)');
        $this->addSql('ALTER TABLE {prefix}order_lines ADD refunded_amount NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL');
        $this->addSql('ALTER TABLE {prefix}orders ADD refunded_tax NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, ADD paid_amount NUMERIC(19, 4) DEFAULT NULL');
        $this->addSql('ALTER TABLE {prefix}refunds ADD tax NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL, ADD taxes JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE {prefix}report_order_lines ADD refunded NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL');
        $this->addSql('ALTER TABLE {prefix}report_refunds ADD tax NUMERIC(19, 4) DEFAULT \'0.0000\' NOT NULL');
        // Orders paid so far were paid their total; earlier refunds and credit notes say no tax.
        $this->addSql('UPDATE {prefix}orders SET paid_amount = total WHERE paid_at IS NOT NULL');
        $this->addSql("UPDATE {prefix}refunds SET taxes = '[]'");
        $this->addSql("UPDATE {prefix}invoices SET taxes = '[]'");
        $this->addSql('ALTER TABLE {prefix}refunds MODIFY taxes JSON NOT NULL');
        $this->addSql('ALTER TABLE {prefix}invoices MODIFY taxes JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}invoices DROP FOREIGN KEY FK_5D0F1A57890A586E');
        $this->addSql('ALTER TABLE {prefix}invoices DROP taxes');
        $this->addSql('ALTER TABLE {prefix}orders DROP refunded_tax, DROP paid_amount');
        $this->addSql('ALTER TABLE {prefix}order_lines DROP refunded_amount');
        $this->addSql('ALTER TABLE {prefix}refunds DROP tax, DROP taxes');
        $this->addSql('ALTER TABLE {prefix}report_order_lines DROP refunded');
        $this->addSql('ALTER TABLE {prefix}report_refunds DROP tax');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
