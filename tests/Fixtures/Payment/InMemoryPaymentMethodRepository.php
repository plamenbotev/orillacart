<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Fixtures\Payment;

use OrillaCart\Payment\Domain\PaymentMethod;
use OrillaCart\Payment\Domain\PaymentMethodRepository;

final class InMemoryPaymentMethodRepository implements PaymentMethodRepository
{
    /** @var array<string, PaymentMethod> */
    private array $methods = [];

    public function all(): array
    {
        $methods = array_values($this->methods);
        usort($methods, static fn(PaymentMethod $a, PaymentMethod $b): int => [$a->position(), $a->gateway()] <=> [$b->position(), $b->gateway()]);

        return $methods;
    }

    public function findByGateway(string $gateway): ?PaymentMethod
    {
        return $this->methods[$gateway] ?? null;
    }

    public function save(PaymentMethod $method): void
    {
        $this->methods[$method->gateway()] = $method;
    }
}
