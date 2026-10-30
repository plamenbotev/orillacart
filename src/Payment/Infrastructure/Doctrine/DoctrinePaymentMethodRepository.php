<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use OrillaCart\Payment\Domain\PaymentMethod;
use OrillaCart\Payment\Domain\PaymentMethodRepository;

/**
 * The payment methods, read once per request (a checkout asks for them several times); a save reads them anew.
 */
final class DoctrinePaymentMethodRepository implements PaymentMethodRepository
{
    private const string CACHE_GROUP = 'orillacart_request';
    private const string CACHE_KEY = 'payment_methods';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        // What was read goes with the entities: a worker forgets them between batches (LoadedEntities).
        $entityManager->getEventManager()->addEventListener(Events::onClear, $this);
    }

    /** @internal Doctrine's, when the entity manager forgets what it loaded */
    public function onClear(): void
    {
        $this->forget();
    }

    public function all(): array
    {
        /** @var list<PaymentMethod> */
        return $this->kept(fn(): mixed => $this->entityManager->getRepository(PaymentMethod::class)->findBy([], ['position' => 'ASC', 'gateway' => 'ASC']));
    }

    public function findByGateway(string $gateway): ?PaymentMethod
    {
        return array_find($this->all(), static fn(PaymentMethod $method): bool => $method->gateway() === $gateway);
    }

    public function save(PaymentMethod $method): void
    {
        $this->entityManager->persist($method);
        $this->entityManager->flush();
        $this->forget();
    }

    /**
     * Read once per request: kept in WordPress's cache for this request only (never a persistent object cache),
     * which a worker empties between rounds and a site of a network has its own of.
     *
     * @param \Closure(): mixed $read the query's result, a list
     *
     * @return array<mixed>
     */
    private function kept(\Closure $read): array
    {
        wp_cache_add_non_persistent_groups([self::CACHE_GROUP]);
        $kept = wp_cache_get(self::CACHE_KEY, self::CACHE_GROUP);
        if (!\is_array($kept)) {
            $kept = $read();
            $kept = \is_array($kept) ? $kept : [];
            wp_cache_set(self::CACHE_KEY, $kept, self::CACHE_GROUP);
        }

        return $kept;
    }

    private function forget(): void
    {
        wp_cache_delete(self::CACHE_KEY, self::CACHE_GROUP);
    }
}
