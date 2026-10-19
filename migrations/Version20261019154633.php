<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261019154633 extends Migration
{
    public function getDescription(): string
    {
        return 'Order addresses hold empty text where there is none, so their columns are NOT NULL';
    }

    public function up(Schema $schema): void
    {
        // Orders saved before hold NULL where there is no address, or no street once anonymised.
        $this->addSql('UPDATE {prefix}orders SET billing_first_name = COALESCE(billing_first_name, \'\'), billing_last_name = COALESCE(billing_last_name, \'\'), billing_street = COALESCE(billing_street, \'\'), billing_city = COALESCE(billing_city, \'\'), billing_postcode = COALESCE(billing_postcode, \'\'), billing_country = COALESCE(billing_country, \'\'), billing_phone = COALESCE(billing_phone, \'\'), shipping_first_name = COALESCE(shipping_first_name, \'\'), shipping_last_name = COALESCE(shipping_last_name, \'\'), shipping_street = COALESCE(shipping_street, \'\'), shipping_city = COALESCE(shipping_city, \'\'), shipping_postcode = COALESCE(shipping_postcode, \'\'), shipping_country = COALESCE(shipping_country, \'\'), shipping_phone = COALESCE(shipping_phone, \'\')');
        $this->addSql('ALTER TABLE {prefix}orders CHANGE billing_first_name billing_first_name VARCHAR(200) DEFAULT \'\' NOT NULL, CHANGE billing_last_name billing_last_name VARCHAR(200) DEFAULT \'\' NOT NULL, CHANGE billing_street billing_street VARCHAR(200) DEFAULT \'\' NOT NULL, CHANGE billing_city billing_city VARCHAR(200) DEFAULT \'\' NOT NULL, CHANGE billing_postcode billing_postcode VARCHAR(20) DEFAULT \'\' NOT NULL, CHANGE billing_country billing_country VARCHAR(2) DEFAULT \'\' NOT NULL, CHANGE billing_phone billing_phone VARCHAR(40) DEFAULT \'\' NOT NULL, CHANGE shipping_first_name shipping_first_name VARCHAR(200) DEFAULT \'\' NOT NULL, CHANGE shipping_last_name shipping_last_name VARCHAR(200) DEFAULT \'\' NOT NULL, CHANGE shipping_street shipping_street VARCHAR(200) DEFAULT \'\' NOT NULL, CHANGE shipping_city shipping_city VARCHAR(200) DEFAULT \'\' NOT NULL, CHANGE shipping_postcode shipping_postcode VARCHAR(20) DEFAULT \'\' NOT NULL, CHANGE shipping_country shipping_country VARCHAR(2) DEFAULT \'\' NOT NULL, CHANGE shipping_phone shipping_phone VARCHAR(40) DEFAULT \'\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE {prefix}orders CHANGE billing_first_name billing_first_name VARCHAR(200) DEFAULT NULL, CHANGE billing_last_name billing_last_name VARCHAR(200) DEFAULT NULL, CHANGE billing_street billing_street VARCHAR(200) DEFAULT NULL, CHANGE billing_city billing_city VARCHAR(200) DEFAULT NULL, CHANGE billing_postcode billing_postcode VARCHAR(20) DEFAULT NULL, CHANGE billing_country billing_country VARCHAR(2) DEFAULT NULL, CHANGE billing_phone billing_phone VARCHAR(40) DEFAULT NULL, CHANGE shipping_first_name shipping_first_name VARCHAR(200) DEFAULT NULL, CHANGE shipping_last_name shipping_last_name VARCHAR(200) DEFAULT NULL, CHANGE shipping_street shipping_street VARCHAR(200) DEFAULT NULL, CHANGE shipping_city shipping_city VARCHAR(200) DEFAULT NULL, CHANGE shipping_postcode shipping_postcode VARCHAR(20) DEFAULT NULL, CHANGE shipping_country shipping_country VARCHAR(2) DEFAULT NULL, CHANGE shipping_phone shipping_phone VARCHAR(40) DEFAULT NULL');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
