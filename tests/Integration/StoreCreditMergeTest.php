<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use Doctrine\DBAL\Connection;
use OrillaCart\Platform\Doctrine\DatabaseConfig;
use OrillaCart\Platform\Doctrine\DatabaseMigrator;
use OrillaCart\Platform\Doctrine\EntityManagerFactory;
use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\PluginContext;
use PHPUnit\Framework\TestCase;

/**
 * Runs inside wp-env. The key that gives a customer one store credit (Version20261110210000) is made also where
 * two first credits at once each made a row before it: the rows become one, with all their entries and money.
 */
final class StoreCreditMergeTest extends TestCase
{
    private const int CUSTOMER = 999_999_512;
    private const int OTHER = 999_999_513;

    private Connection $connection;
    private string $cards;
    private string $entries;

    protected function setUp(): void
    {
        $this->migrator()->migrate();
        $database = DatabaseConfig::fromWordPress();
        $this->connection = new EntityManagerFactory($database, new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION))->create()->getConnection();
        $this->cards = $database->tablePrefix . 'gift_cards';
        $this->entries = $database->tablePrefix . 'gift_card_entries';
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement("DELETE FROM {$this->entries} WHERE card_id IN (SELECT id FROM {$this->cards} WHERE customer_id IN (?, ?))", [self::CUSTOMER, self::OTHER]);
        $this->connection->executeStatement("DELETE FROM {$this->cards} WHERE customer_id IN (?, ?)", [self::CUSTOMER, self::OTHER]);
        // Whatever a failure left: the key the plugin's tables have.
        $this->migrator()->migrate();
    }

    public function testACustomersSecondCreditsBecomeTheFirstBeforeTheKeyIsMade(): void
    {
        // As a site that ran the old migrations: the index not unique, and the rows two first credits at once made.
        $this->connection->executeStatement("DROP INDEX gift_card_of_customer ON {$this->cards}");
        $this->connection->executeStatement("CREATE INDEX gift_cards_of_customer ON {$this->cards} (customer_id, kind)");
        $first = $this->credit(self::CUSTOMER, ['10.0000', '-4.0000']);
        $second = $this->credit(self::CUSTOMER, ['25.0000']);
        $third = $this->credit(self::CUSTOMER, []);
        $other = $this->credit(self::OTHER, ['7.0000']);
        $migrations = DatabaseConfig::fromWordPress()->tablePrefix . 'migrations';
        self::assertSame(1, $this->connection->executeStatement("DELETE FROM {$migrations} WHERE version LIKE '%Version20261110210000'"));

        $this->migrator()->migrate();

        self::assertSame([$first => '31.0000', $other => '7.0000'], array_map(static fn(mixed $balance): string => \is_scalar($balance) ? (string) $balance : '', $this->connection->fetchAllKeyValue("SELECT id, balance FROM {$this->cards} WHERE customer_id IN (?, ?) ORDER BY id", [self::CUSTOMER, self::OTHER])), 'one credit each, with all the money');
        self::assertEquals(3, $this->connection->fetchOne("SELECT COUNT(*) FROM {$this->entries} WHERE card_id = ?", [$first]), 'and all the entries');
        self::assertEquals(0, $this->connection->fetchOne("SELECT COUNT(*) FROM {$this->entries} WHERE card_id IN (?, ?)", [$second, $third]));
        self::assertEquals(0, $this->connection->fetchOne("SELECT NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'gift_card_of_customer' LIMIT 1", [$this->cards]), 'the key is there');
        self::assertFalse($this->connection->fetchOne("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'gift_cards_of_customer' LIMIT 1", [$this->cards]), 'and the old index gone');
    }

    private function migrator(): DatabaseMigrator
    {
        $migrator = new Kernel(new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION))->container()->get(DatabaseMigrator::class);
        self::assertInstanceOf(DatabaseMigrator::class, $migrator);

        return $migrator;
    }

    /**
     * @param list<string> $amounts its entries
     */
    private function credit(int $customer, array $amounts): int
    {
        $balance = array_sum(array_map(floatval(...), $amounts));
        $this->connection->insert($this->cards, [
            'code_hash' => null,
            'code_end' => '',
            'initial' => '0.0000',
            'balance' => number_format($balance, 4, '.', ''),
            'expires_at' => null,
            'active' => 1,
            'recipient_name' => '',
            'recipient_email' => '',
            'message' => '',
            'kind' => 'store_credit',
            'currency' => 'EUR',
            'customer_id' => $customer,
            'order_id' => null,
            'created_at' => '2026-11-10 12:00:00',
        ]);
        $id = (int) $this->connection->lastInsertId();
        foreach ($amounts as $amount) {
            $this->connection->insert($this->entries, ['amount' => $amount, 'note' => 'Test', 'card_id' => $id, 'at' => '2026-11-10 12:00:00', 'order_id' => null, 'user_id' => null]);
        }

        return $id;
    }
}
