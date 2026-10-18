<?php

declare(strict_types=1);

namespace OrillaCart\Invoicing\Infrastructure\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Invoicing\Domain\Invoice;
use OrillaCart\Invoicing\Domain\InvoiceCounter;
use OrillaCart\Invoicing\Domain\InvoiceRepository;

final readonly class DoctrineInvoiceRepository implements InvoiceRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function findByNumber(string $number): ?Invoice
    {
        return $this->entityManager->getRepository(Invoice::class)->findOneBy(['number' => $number]);
    }

    public function ofOrder(int $orderId): array
    {
        /** @var list<Invoice> */
        return $this->entityManager->getRepository(Invoice::class)->findBy(['orderId' => $orderId], ['id' => 'ASC']);
    }

    public function lockedCounter(string $series): InvoiceCounter
    {
        // A new series starts at 1; two requests starting it together both find the one row then.
        $table = $this->entityManager->getClassMetadata(InvoiceCounter::class)->getTableName();
        $this->entityManager->getConnection()->executeStatement("INSERT IGNORE INTO {$table} (series, next) VALUES (?, 1)", [$series]);
        $counter = $this->entityManager->find(InvoiceCounter::class, $series);
        if ($counter === null) {
            throw new \LogicException("The invoice series {$series} was not made.");
        }
        // Read again under the lock: a copy loaded earlier in the request may be older than the row.
        $this->entityManager->refresh($counter, LockMode::PESSIMISTIC_WRITE);

        return $counter;
    }

    public function save(Invoice $invoice): void
    {
        $this->entityManager->persist($invoice);
        $this->entityManager->flush();
    }

    public function saveCounter(InvoiceCounter $counter): void
    {
        $this->entityManager->persist($counter);
        $this->entityManager->flush();
    }
}
