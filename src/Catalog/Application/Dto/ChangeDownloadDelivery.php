<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Shared\Files\DeliveryMethod;
use OrillaCart\Shared\Files\DeliverySettings;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Command: send download files another way. The method is kept only when a test file goes through it.
 */
final readonly class ChangeDownloadDelivery
{
    public function __construct(
        /** php, x_sendfile (Apache, LiteSpeed) or x_accel_redirect (Nginx) */
        #[Assert\Choice(callback: [DeliveryMethod::class, 'values'])]
        public string $method,
        /** For Nginx: the internal location the server maps to the downloads folder. */
        #[Assert\Length(max: 100)]
        #[Assert\Regex(pattern: '#^/?[A-Za-z0-9_/-]*/?$#', message: 'Use a path such as /orillacart-files/.')]
        public string $internalPrefix = DeliverySettings::DEFAULT_PREFIX,
    ) {}
}
