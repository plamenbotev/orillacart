<?php

declare(strict_types=1);

namespace OrillaCart\Extension\Marketing;

/**
 * Someone who signed up for the shop's newsletter, with what they agreed to and when: the consent a mailing
 * list may have to keep. Since version 6.
 */
final readonly class Subscriber
{
    public function __construct(
        public string $email,
        public string $firstName,
        public string $lastName,
        /** The words of the box they ticked. */
        public string $consent,
        public \DateTimeImmutable $subscribedAt,
        /** Where they signed up: "checkout" or "account". */
        public string $source,
    ) {}
}
