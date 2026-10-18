<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261018081200 extends Migration
{
    public function getDescription(): string
    {
        return 'Shipments booked with couriers; the office or locker an order goes to';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE {prefix}consignments (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, tracking VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL, order_id INT NOT NULL, carrier_id INT NOT NULL, carrier VARCHAR(100) NOT NULL, method VARCHAR(40) NOT NULL, number VARCHAR(64) NOT NULL, tracking_url VARCHAR(500) DEFAULT NULL, booked_at DATETIME NOT NULL, INDEX consignment_order (order_id), INDEX consignment_status (status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE {prefix}orders ADD pickup_point_id VARCHAR(64) DEFAULT NULL, ADD pickup_point VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}consignments');
        $this->addSql('ALTER TABLE {prefix}orders DROP pickup_point_id, DROP pickup_point');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
