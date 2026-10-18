<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application\Dto;

use OrillaCart\Shared\Files\DeliveryCheck;
use OrillaCart\Shared\Files\DeliverySettings;

/**
 * How download files are sent, and what the last test found when there was one.
 */
final readonly class DownloadDeliveryView
{
    /**
     * @param bool|null $works      whether the test file went through the method asked for; null when nothing was tested
     * @param string    $serverRule what the web server needs for the method asked for
     */
    public function __construct(
        public string $method,
        public string $internalPrefix,
        public ?bool $works = null,
        public string $message = '',
        public string $serverRule = '',
    ) {}

    public static function of(DeliverySettings $settings, ?DeliveryCheck $check = null): self
    {
        return new self($settings->method->value, $settings->internalPrefix, $check?->works, $check->message ?? '', $check->serverRule ?? '');
    }
}
