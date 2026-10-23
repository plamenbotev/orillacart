<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Orders priced in another currency than the shop's keep the shop's currency and the exchange rate, for the VAT in
 * the shop's currency on their invoices.
 */
final class Version20261023122830 extends Migration
{
    public function getDescription(): string
    {
        return 'Orders keep the exchange rate they were priced at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}orders ADD shop_currency VARCHAR(3) DEFAULT NULL, ADD exchange_rate NUMERIC(18, 8) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}orders DROP shop_currency, DROP exchange_rate');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
