<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Domain;

interface FormUploadRepository
{
    public function find(int $id): ?FormUpload;

    public function withToken(string $token): ?FormUpload;

    public function withTokenHash(string $hash): ?FormUpload;

    /** What the sender sent since the time, in bytes. */
    public function sentBy(string $sender, \DateTimeImmutable $since): int;

    /**
     * @return list<FormUpload>
     */
    public function ofOrder(int $orderId): array;

    /**
     * @return list<FormUpload> those no order took: sent before $sent and never in a cart, or last in one before $held
     */
    public function untakenBefore(\DateTimeImmutable $sent, \DateTimeImmutable $held, int $limit): array;

    /** What the uploads no cart or order took come to, in bytes. */
    public function untakenBytes(): int;

    /** What every upload no order took, in carts or not, comes to, in bytes. */
    public function unorderedBytes(): int;

    public function save(FormUpload $upload): void;

    public function remove(FormUpload $upload): void;
}
