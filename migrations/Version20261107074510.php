<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * High volumes (design 55): the words the shop finds an order by in one FULLTEXT-indexed column, instead of a
 * scan of fifteen; and the indexes the lists filter and sort by: orders by the day placed, order lines by product,
 * stock levels by quantity (low stock), the search index by what the shop's listing filters and orders by.
 */
final class Version20261107074510 extends Migration
{
    public function getDescription(): string
    {
        return 'Order words and the indexes of the lists';
    }

    public function up(Schema $schema): void
    {
        // Each step guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('orders', 'words')) {
            $this->addSql('ALTER TABLE {prefix}orders ADD words LONGTEXT NOT NULL');
        }
        // The orders placed so far get their words from their columns and lines; new ones gather their own.
        $this->addSql(<<<'SQL'
            UPDATE {prefix}orders o SET o.words = TRIM(CONCAT_WS(' ',
                o.email, o.vat_number, o.transaction_id,
                o.billing_first_name, o.billing_last_name, o.billing_phone, o.billing_street, o.billing_city, o.billing_postcode,
                o.shipping_first_name, o.shipping_last_name, o.shipping_phone, o.shipping_street, o.shipping_city, o.shipping_postcode,
                (SELECT GROUP_CONCAT(CONCAT_WS(' ', l.name, JSON_UNQUOTE(JSON_EXTRACT(l.shop_words, '$.name')), l.sku) SEPARATOR ' ')
                   FROM {prefix}order_lines l WHERE l.order_id = o.id)
            )) WHERE o.words = ''
            SQL);
        foreach ([
            ['orders', 'order_words', 'CREATE FULLTEXT INDEX order_words ON {prefix}orders (words)'],
            ['orders', 'order_placed', 'CREATE INDEX order_placed ON {prefix}orders (placed_at)'],
            ['order_lines', 'line_product', 'CREATE INDEX line_product ON {prefix}order_lines (product_id)'],
            ['stock_levels', 'stock_quantity', 'CREATE INDEX stock_quantity ON {prefix}stock_levels (quantity)'],
            ['search_documents', 'search_listing', 'CREATE INDEX search_listing ON {prefix}search_documents (original, menu_order, title)'],
            ['search_documents', 'search_locale_listing', 'CREATE INDEX search_locale_listing ON {prefix}search_documents (locale, menu_order, title)'],
        ] as [$table, $index, $sql]) {
            if (!$this->hasIndex($table, $index)) {
                $this->addSql($sql);
            }
        }
        // The variants' index covers the stock and price conditions too: the filter's EXISTS reads the index only.
        $this->addSql('DROP INDEX search_variant_product ON {prefix}search_variants');
        $this->addSql('CREATE INDEX search_variant_product ON {prefix}search_variants (product_id, in_stock, price)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX order_placed ON {prefix}orders');
        $this->addSql('DROP INDEX order_words ON {prefix}orders');
        $this->addSql('ALTER TABLE {prefix}orders DROP words');
        $this->addSql('DROP INDEX line_product ON {prefix}order_lines');
        $this->addSql('DROP INDEX search_listing ON {prefix}search_documents');
        $this->addSql('DROP INDEX search_locale_listing ON {prefix}search_documents');
        $this->addSql('DROP INDEX search_variant_product ON {prefix}search_variants');
        $this->addSql('CREATE INDEX search_variant_product ON {prefix}search_variants (product_id)');
        $this->addSql('DROP INDEX stock_quantity ON {prefix}stock_levels');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
