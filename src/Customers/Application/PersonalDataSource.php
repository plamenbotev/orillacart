<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

/**
 * What an area keeps of a person besides their orders and account, for their export (WordPress's privacy tools):
 * back-in-stock requests, carts left at checkout, exemption certificates, gift cards made out to them. Each area
 * that forgets a person on a privacy request also says what it holds of them here.
 */
interface PersonalDataSource
{
    public const string TAG = 'orillacart.personal_data';

    /**
     * @param int|null $customerId their account, when they have one
     *
     * @return list<array{group: string, label: string, id: string, data: list<array{name: string, value: string}>}>
     */
    public function personalData(string $email, ?int $customerId): array;
}
