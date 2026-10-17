<?php

declare(strict_types=1);

namespace OrillaCart\Payment\Domain;

interface PaymentMethodRepository
{
    /** @return list<PaymentMethod> saved methods by position, then gateway */
    public function all(): array;

    public function findByGateway(string $gateway): ?PaymentMethod;

    public function save(PaymentMethod $method): void;
}
