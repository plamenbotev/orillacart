<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261018082641 extends Migration
{
    public function getDescription(): string
    {
        return 'The filters the shop offers on its shop page';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE {prefix}search_filters (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(100) NOT NULL, filter_key VARCHAR(40) NOT NULL, attributes JSON NOT NULL, position INT NOT NULL, UNIQUE INDEX search_filter_key (filter_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}search_filters');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
