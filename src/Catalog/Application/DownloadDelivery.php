<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Application;

use OrillaCart\Catalog\Application\Dto\ChangeDownloadDelivery;
use OrillaCart\Catalog\Application\Dto\DownloadDeliveryView;
use OrillaCart\Shared\Files\DeliveryMethod;
use OrillaCart\Shared\Files\DeliverySettings;
use OrillaCart\Shared\Files\FileDelivery;

/**
 * Whether PHP or the web server sends bought files, as the shop settings screen runs it.
 */
final readonly class DownloadDelivery
{
    public function __construct(private FileDelivery $delivery) {}

    public function current(): DownloadDeliveryView
    {
        return DownloadDeliveryView::of($this->delivery->settings());
    }

    /**
     * Tests the method and keeps it when the test file went through; the view then shows the method in use, and
     * the test's outcome either way.
     */
    public function change(ChangeDownloadDelivery $command): DownloadDeliveryView
    {
        $check = $this->delivery->change(new DeliverySettings(DeliveryMethod::from($command->method), $command->internalPrefix));

        return DownloadDeliveryView::of($this->delivery->settings(), $check);
    }
}
