<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Integration;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Coupons\Infrastructure\Doctrine\DoctrineRedemptionRepository;
use OrillaCart\Platform\Doctrine\DatabaseConfig;
use OrillaCart\Platform\Doctrine\EntityManagerFactory;
use OrillaCart\Platform\PluginContext;
use PHPUnit\Framework\TestCase;

/**
 * Runs inside wp-env, with two connections as two checkouts: a customer's first order holds their turn until it
 * is saved, and only that customer's next first order waits for it.
 */
final class FirstOrderTurnTest extends TestCase
{
    private EntityManagerInterface $first;
    private EntityManagerInterface $second;

    protected function setUp(): void
    {
        $database = DatabaseConfig::fromWordPress();
        $this->first = new EntityManagerFactory($database, new PluginContext(\dirname(__DIR__, 2) . '/orillacart.php', ORILLACART_VERSION))->create();
        // The plugin's connections all share wpdb's: the other checkout is another request, with its own.
        $events = $this->first->getEventManager();
        self::assertInstanceOf(EventManager::class, $events);
        $this->second = new EntityManager(DriverManager::getConnection($database->connectionParams()), $this->first->getConfiguration(), $events);
        $this->second->getConnection()->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        foreach ([$this->first, $this->second] as $entityManager) {
            if ($entityManager->getConnection()->isTransactionActive()) {
                $entityManager->getConnection()->rollBack();
            }
        }
        $this->second->getConnection()->close();
    }

    public function testTheSameCustomersSecondFirstOrderWaitsForTheFirst(): void
    {
        $email = 'turn-' . bin2hex(random_bytes(4)) . '@example.com';
        $this->first->getConnection()->beginTransaction();
        new DoctrineRedemptionRepository($this->first)->takeFirstOrderTurn(null, $email);
        $this->second->getConnection()->beginTransaction();

        $this->assertWaits(fn() => new DoctrineRedemptionRepository($this->second)->takeFirstOrderTurn(null, strtoupper($email)));
    }

    public function testAnotherCustomersFirstOrderDoesNotWait(): void
    {
        $this->first->getConnection()->beginTransaction();
        new DoctrineRedemptionRepository($this->first)->takeFirstOrderTurn(7, 'turn-' . bin2hex(random_bytes(4)) . '@example.com');
        $this->second->getConnection()->beginTransaction();
        $started = microtime(true);
        new DoctrineRedemptionRepository($this->second)->takeFirstOrderTurn(8, 'turn-' . bin2hex(random_bytes(4)) . '@example.com');
        self::assertLessThan(0.5, microtime(true) - $started, 'another customer does not wait');
    }

    /** It waited for the first's lock until its timeout, a second (which plain mysqli may not throw for). */
    private function assertWaits(\Closure $take): void
    {
        $started = microtime(true);
        try {
            $take();
        } catch (LockWaitTimeoutException) {
        }

        self::assertGreaterThanOrEqual(0.9, microtime(true) - $started, 'the same customer waits');
    }
}
