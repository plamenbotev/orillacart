<?php

declare(strict_types=1);

namespace OrillaCart\Exchange\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use OrillaCart\Exchange\Domain\ProductImport;
use OrillaCart\Exchange\Domain\ProductImportRepository;

final readonly class DoctrineProductImports implements ProductImportRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(int $id): ?ProductImport
    {
        return $this->entityManager->find(ProductImport::class, $id);
    }

    public function latest(): ?ProductImport
    {
        return $this->entityManager->getRepository(ProductImport::class)->findOneBy([], ['id' => 'DESC']);
    }

    public function save(ProductImport $import): void
    {
        $this->entityManager->persist($import);
        $this->entityManager->flush();
    }

    public function progress(ProductImport $import): void
    {
        $this->entityManager->getConnection()->update(
            $this->entityManager->getClassMetadata(ProductImport::class)->getTableName(),
            [
                'status' => $import->status()->value,
                'position' => $import->position(),
                'line' => $import->line(),
                'done' => $import->done(),
                'created' => $import->created(),
                'updated' => $import->updated(),
                'failed' => $import->failed(),
                'errors' => (string) json_encode($import->errors(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                'finished_at' => $import->finishedAt()?->format('Y-m-d H:i:s'),
            ],
            ['id' => $import->id()],
        );
    }

    public function remove(ProductImport $import): void
    {
        $this->entityManager->remove($import);
        $this->entityManager->flush();
    }
}
