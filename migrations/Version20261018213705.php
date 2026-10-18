<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Orders keep a business customer's VAT number, and whether they were sold without VAT (reverse charge).
 */
final class Version20261018213705 extends Migration
{
    public function getDescription(): string
    {
        return 'Orders keep the VAT number and the reverse charge';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}orders ADD vat_number VARCHAR(20) DEFAULT NULL, ADD reverse_charge TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}orders DROP vat_number, DROP reverse_charge');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
