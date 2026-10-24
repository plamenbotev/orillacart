<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Notifications;

use OrillaCart\Notifications\Domain\BackInStockRequest;
use OrillaCart\Notifications\Domain\BackInStockRequests;

final class InMemoryBackInStockRequests implements BackInStockRequests
{
    /** @var list<BackInStockRequest> */
    public array $requests = [];

    public function has(string $item, string $email): bool
    {
        return array_filter($this->requests, static fn(BackInStockRequest $request): bool => $request->item() === $item && $request->email() === $email) !== [];
    }

    public function wanted(string $item): bool
    {
        return $this->forItem($item, 1) !== [];
    }

    public function forItem(string $item, int $limit): array
    {
        return \array_slice(array_values(array_filter($this->requests, static fn(BackInStockRequest $request): bool => $request->item() === $item)), 0, $limit);
    }

    public function save(BackInStockRequest $request): void
    {
        $this->requests[] = $request;
    }

    public function remove(BackInStockRequest $request): void
    {
        $this->requests = array_values(array_filter($this->requests, static fn(BackInStockRequest $other): bool => $other !== $request));
    }

    public function ofEmail(string $email): array
    {
        return array_values(array_filter($this->requests, static fn(BackInStockRequest $request): bool => $request->email() === $email));
    }

    public function forgetEmail(string $email): int
    {
        $before = \count($this->requests);
        $this->requests = array_values(array_filter($this->requests, static fn(BackInStockRequest $request): bool => $request->email() !== $email));

        return $before - \count($this->requests);
    }

    public function forgetBefore(\DateTimeImmutable $before): int
    {
        $at = new \ReflectionProperty(BackInStockRequest::class, 'at');
        $count = \count($this->requests);
        $this->requests = array_values(array_filter($this->requests, static fn(BackInStockRequest $request): bool => $at->getValue($request) >= $before));

        return $count - \count($this->requests);
    }
}
