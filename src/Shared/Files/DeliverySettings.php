<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Files;

/**
 * How private files are sent. For Nginx, the internal location's path, which the server maps to the folder.
 */
final readonly class DeliverySettings
{
    public const string DEFAULT_PREFIX = '/orillacart-files/';

    public function __construct(
        public DeliveryMethod $method = DeliveryMethod::Php,
        public string $internalPrefix = self::DEFAULT_PREFIX,
    ) {}
}
