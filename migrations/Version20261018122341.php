<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261018122341 extends Migration
{
    public function getDescription(): string
    {
        return 'Customer groups and price rules';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE {prefix}customer_group_members (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, group_id INT NOT NULL, INDEX customer_groups_of (customer_id), UNIQUE INDEX customer_group_member (group_id, customer_id), INDEX IDX_76A6C440FE54D947 (group_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE {prefix}customer_groups (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, UNIQUE INDEX customer_group_name (name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE {prefix}price_rules (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, active TINYINT NOT NULL, group_ids JSON NOT NULL, product_ids JSON NOT NULL, category_ids JSON NOT NULL, brand_ids JSON NOT NULL, tiers JSON NOT NULL, starts_at DATETIME DEFAULT NULL, ends_at DATETIME DEFAULT NULL, with_sales TINYINT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE {prefix}customer_group_members ADD CONSTRAINT FK_76A6C440FE54D947 FOREIGN KEY (group_id) REFERENCES {prefix}customer_groups (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}customer_group_members DROP FOREIGN KEY FK_76A6C440FE54D947');
        $this->addSql('DROP TABLE {prefix}customer_group_members');
        $this->addSql('DROP TABLE {prefix}customer_groups');
        $this->addSql('DROP TABLE {prefix}price_rules');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
