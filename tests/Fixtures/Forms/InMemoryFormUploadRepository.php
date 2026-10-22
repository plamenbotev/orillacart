<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Forms;

use OrillaCart\Forms\Domain\FormUpload;
use OrillaCart\Forms\Domain\FormUploadRepository;

final class InMemoryFormUploadRepository implements FormUploadRepository
{
    /** @var array<int, FormUpload> */
    public array $uploads = [];
    private int $next = 1;

    public function find(int $id): ?FormUpload
    {
        return $this->uploads[$id] ?? null;
    }

    public function withToken(string $token): ?FormUpload
    {
        $hash = new \ReflectionProperty(FormUpload::class, 'tokenHash');
        foreach ($this->uploads as $upload) {
            if ($hash->getValue($upload) === FormUpload::hash($token)) {
                return $upload;
            }
        }

        return null;
    }

    public function ofOrder(int $orderId): array
    {
        return array_values(array_filter($this->uploads, static fn(FormUpload $upload): bool => $upload->orderId() === $orderId));
    }

    public function untakenBefore(\DateTimeImmutable $sent, \DateTimeImmutable $held, int $limit): array
    {
        return \array_slice(array_values(array_filter($this->uploads, static fn(FormUpload $upload): bool => $upload->orderId() === null && ($upload->heldAt() === null ? $upload->createdAt() < $sent : $upload->heldAt() < $held))), 0, $limit);
    }

    public function untakenBytes(): int
    {
        return array_sum(array_map(static fn(FormUpload $upload): int => $upload->orderId() === null ? $upload->size() : 0, $this->uploads));
    }

    public function save(FormUpload $upload): void
    {
        $id = new \ReflectionProperty(FormUpload::class, 'id');
        if ($id->getValue($upload) === null) {
            $id->setValue($upload, $this->next++);
        }
        $this->uploads[$upload->id()] = $upload;
    }

    public function remove(FormUpload $upload): void
    {
        unset($this->uploads[$upload->id()]);
    }
}
