<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

final class Version20261018070800 extends Migration
{
    public function getDescription(): string
    {
        return 'The storefront search index and the queue that feeds it; the plugin now needs MySQL 8.0 or MariaDB 10.6';
    }

    public function up(Schema $schema): void
    {
        // Workers claim queued products with FOR UPDATE SKIP LOCKED.
        $version = $this->connection->getServerVersion();
        $mariaDb = preg_match('/(\d+\.\d+\.\d+)-MariaDB/i', $version, $found) === 1;
        $number = $mariaDb ? $found[1] : (preg_match('/^\d+\.\d+\.\d+/', $version, $plain) === 1 ? $plain[0] : '0');
        $this->abortIf(
            version_compare($number, $mariaDb ? '10.6.0' : '8.0.1', '<'),
            \sprintf('OrillaCart needs MySQL 8.0 or MariaDB 10.6; this database server is %s.', $version),
        );

        $this->addSql('CREATE TABLE {prefix}index_jobs (version INT NOT NULL, attempts INT NOT NULL, locked_until DATETIME DEFAULT NULL, last_error VARCHAR(255) DEFAULT NULL, product_id INT NOT NULL, available_at DATETIME NOT NULL, INDEX index_job_due (available_at), PRIMARY KEY (product_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE {prefix}search_documents (product_id INT NOT NULL, title VARCHAR(255) NOT NULL, text LONGTEXT NOT NULL, price_min NUMERIC(12, 4) DEFAULT NULL, price_max NUMERIC(12, 4) DEFAULT NULL, in_stock TINYINT NOT NULL, featured TINYINT NOT NULL, on_sale TINYINT NOT NULL, menu_order INT NOT NULL, published_at DATETIME NOT NULL, FULLTEXT INDEX search_title (title), FULLTEXT INDEX search_text (title, text), INDEX search_order (menu_order, title), INDEX search_published (published_at), INDEX search_price (price_min), PRIMARY KEY (product_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE {prefix}search_values (id INT AUTO_INCREMENT NOT NULL, product_id INT NOT NULL, variant_id INT DEFAULT NULL, facet VARCHAR(100) NOT NULL, value VARCHAR(150) NOT NULL, label VARCHAR(255) NOT NULL, INDEX search_value_facet (facet, value, product_id), INDEX search_value_product (product_id, facet), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE {prefix}search_variants (variant_id INT NOT NULL, product_id INT NOT NULL, price NUMERIC(12, 4) DEFAULT NULL, in_stock TINYINT NOT NULL, INDEX search_variant_product (product_id), PRIMARY KEY (variant_id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}index_jobs');
        $this->addSql('DROP TABLE {prefix}search_documents');
        $this->addSql('DROP TABLE {prefix}search_values');
        $this->addSql('DROP TABLE {prefix}search_variants');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
