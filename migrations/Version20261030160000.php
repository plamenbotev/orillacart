<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Orders found by their indexes: by email (a customer's orders, their standing order, what they bought), those
 * that need attention, and the list sorted by total. The Orders screen's filters read their values from a short
 * table kept as orders are saved, filled here from the orders there are.
 */
final class Version20261030160000 extends Migration
{
    public function getDescription(): string
    {
        return 'Orders indexed by email, attention and total; the Orders screen\'s filter values kept apart';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off between the statements goes on from where it stopped when it runs again.
        if (!$this->hasIndex('orders', 'order_email')) {
            $this->addSql('CREATE INDEX order_email ON {prefix}orders (email)');
        }
        if (!$this->hasIndex('orders', 'order_attention')) {
            $this->addSql('CREATE INDEX order_attention ON {prefix}orders (attention_since)');
        }
        if (!$this->hasIndex('orders', 'order_total')) {
            $this->addSql('CREATE INDEX order_total ON {prefix}orders (total, id)');
        }
        if (!$this->hasTable('order_facets')) {
            $this->addSql('CREATE TABLE {prefix}order_facets (kind VARCHAR(10) NOT NULL, value VARCHAR(200) NOT NULL, PRIMARY KEY (kind, value)) DEFAULT CHARACTER SET utf8mb4');
        }
        // Filling it again changes nothing: a value is there once.
        $this->addSql("INSERT IGNORE INTO {prefix}order_facets (kind, value) SELECT DISTINCT 'currency', currency FROM {prefix}orders");
        $this->addSql("INSERT IGNORE INTO {prefix}order_facets (kind, value) SELECT DISTINCT 'country', billing_country FROM {prefix}orders WHERE billing_country <> ''");
        $this->addSql("INSERT IGNORE INTO {prefix}order_facets (kind, value) SELECT DISTINCT 'country', shipping_country FROM {prefix}orders WHERE shipping_country IS NOT NULL AND shipping_country <> ''");
        $this->addSql("INSERT IGNORE INTO {prefix}order_facets (kind, value) SELECT DISTINCT 'shipping', LEFT(TRIM(COALESCE(JSON_VALUE(shop_words, '$.shipping_name'), shipping_name)), 200) FROM {prefix}orders WHERE TRIM(COALESCE(JSON_VALUE(shop_words, '$.shipping_name'), shipping_name, '')) <> ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE {prefix}order_facets');
        $this->addSql('DROP INDEX order_total ON {prefix}orders');
        $this->addSql('DROP INDEX order_attention ON {prefix}orders');
        $this->addSql('DROP INDEX order_email ON {prefix}orders');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
