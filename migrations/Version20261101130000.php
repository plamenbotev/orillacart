<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Charges: prices of their own with no product, kept in the cart by add-ons and added to orders by the shop. A
 * cart line may hold no product; a charge keeps its label, so much each and how it is taxed, and who charges it.
 */
final class Version20261101130000 extends Migration
{
    public function getDescription(): string
    {
        return 'Charges in carts and orders';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        $this->addSql('ALTER TABLE {prefix}cart_lines MODIFY product_id INT DEFAULT NULL');
        if (!$this->hasColumn('cart_lines', 'charge_amount')) {
            $this->addSql('ALTER TABLE {prefix}cart_lines ADD charge_amount NUMERIC(19, 4) DEFAULT NULL, ADD charge_label VARCHAR(100) DEFAULT NULL, ADD charge_taxable TINYINT DEFAULT NULL, ADD charge_tax_group_id INT DEFAULT NULL');
        }
        if (!$this->hasColumn('order_lines', 'charge_source')) {
            $this->addSql('ALTER TABLE {prefix}order_lines ADD charge_source VARCHAR(50) DEFAULT NULL, ADD charge_taxable TINYINT DEFAULT NULL, ADD charge_tax_group_id INT DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}order_lines DROP charge_source, DROP charge_taxable, DROP charge_tax_group_id');
        $this->addSql('ALTER TABLE {prefix}cart_lines DROP charge_amount, DROP charge_label, DROP charge_taxable, DROP charge_tax_group_id');
        $this->addSql('ALTER TABLE {prefix}cart_lines MODIFY product_id INT NOT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
