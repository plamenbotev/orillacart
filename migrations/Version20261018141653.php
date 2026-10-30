<?php

declare(strict_types=1);

namespace OrillaCart\Migrations;

use Doctrine\DBAL\Schema\Schema;
use OrillaCart\Platform\Doctrine\Migration;

/**
 * Bundle choices: the customer's picks on cart and order lines, and bundle items that list several variations
 * or are optional.
 */
final class Version20261018141653 extends Migration
{
    public function getDescription(): string
    {
        return 'Bundle choices and optional bundle items';
    }

    public function up(Schema $schema): void
    {
        // Guarded, so a run cut off halfway goes on from where it stopped when it runs again.
        if (!$this->hasColumn('cart_lines', 'bundle_choices')) {
            $this->addSql('ALTER TABLE {prefix}cart_lines ADD bundle_choices JSON DEFAULT NULL');
        }
        $this->addSql('UPDATE {prefix}cart_lines SET bundle_choices = \'[]\' WHERE bundle_choices IS NULL');
        $this->addSql('ALTER TABLE {prefix}cart_lines MODIFY bundle_choices JSON NOT NULL');
        if (!$this->hasColumn('order_lines', 'bundle_choices')) {
            $this->addSql('ALTER TABLE {prefix}order_lines ADD bundle_choices JSON DEFAULT NULL');
        }
        // A bundle item named one variation; now it lists the variations it offers.
        foreach ($this->connection->fetchAllAssociative('SELECT id, bundle FROM ' . $this->table('products') . ' WHERE bundle IS NOT NULL') as $row) {
            \assert(\is_string($row['bundle']));
            $bundle = json_decode($row['bundle'], true, flags: JSON_THROW_ON_ERROR);
            \assert(\is_array($bundle) && \is_array($bundle['items']));
            /** @var list<array<string, mixed>> $items */
            $items = $bundle['items'];
            $bundle['items'] = array_map(static fn(array $item): array => [
                'product_id' => $item['product_id'],
                'variation_ids' => $item['variation_ids'] ?? (isset($item['variation_id']) ? [$item['variation_id']] : []),
                'quantity' => $item['quantity'],
                'optional' => $item['optional'] ?? false,
            ], $items);
            $this->addSql('UPDATE {prefix}products SET bundle = ? WHERE id = ?', [json_encode($bundle, JSON_THROW_ON_ERROR), $row['id']]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE {prefix}cart_lines DROP bundle_choices');
        $this->addSql('ALTER TABLE {prefix}order_lines DROP bundle_choices');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
