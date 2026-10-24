<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

/** Every area's personal data of a person. */
interface PersonalDataSources
{
    /**
     * @return list<array{group: string, label: string, id: string, data: list<array{name: string, value: string}>}>
     */
    public function of(string $email, ?int $customerId): array;
}
