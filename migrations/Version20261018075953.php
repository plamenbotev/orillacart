<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261018075953 extends Migration
{
    public function getDescription(): string
    {
        return 'Carriers priced by an add-on\'s shipping method; orders name how they ship as "rate:12" or "carrier:5:express"';
    }

    public function up(Schema $schema): void
    {
        // MySQL takes no literal default on a JSON column: add it empty, fill it, then require it.
        $this->addSql('ALTER TABLE {prefix}carriers ADD method VARCHAR(40) DEFAULT NULL, ADD method_settings JSON DEFAULT NULL, ADD method_taxable TINYINT DEFAULT 1 NOT NULL, ADD method_tax_group_id INT DEFAULT NULL');
        $this->addSql("UPDATE {prefix}carriers SET method_settings = '[]'");
        $this->addSql('ALTER TABLE {prefix}carriers MODIFY method_settings JSON NOT NULL, MODIFY method_taxable TINYINT NOT NULL');
        $this->addSql('ALTER TABLE {prefix}orders ADD shipping_option VARCHAR(100) DEFAULT NULL');
        $this->addSql("UPDATE {prefix}orders SET shipping_option = CONCAT('rate:', shipping_rate_id) WHERE shipping_rate_id IS NOT NULL");
        $this->addSql('ALTER TABLE {prefix}orders DROP shipping_rate_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}carriers DROP method, DROP method_settings, DROP method_taxable, DROP method_tax_group_id');
        $this->addSql('ALTER TABLE {prefix}orders ADD shipping_rate_id INT DEFAULT NULL');
        $this->addSql("UPDATE {prefix}orders SET shipping_rate_id = CAST(SUBSTRING(shipping_option, 6) AS UNSIGNED) WHERE shipping_option LIKE 'rate:%'");
        $this->addSql('ALTER TABLE {prefix}orders DROP shipping_option');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
