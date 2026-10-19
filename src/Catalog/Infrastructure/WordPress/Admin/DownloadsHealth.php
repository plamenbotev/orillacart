<?php

declare(strict_types=1);

namespace OrillaCart\Catalog\Infrastructure\WordPress\Admin;

use OrillaCart\Catalog\Application\DownloadService;
use OrillaCart\Shared\Files\FileDelivery;
use OrillaCart\Shared\Files\StorageCheck;
use OrillaCart\Shared\WordPress\HealthCheck;
use OrillaCart\Shared\WordPress\HealthStatus;

/**
 * Whether the folder of bought files is closed to the web: anyone who guesses a file's address would otherwise
 * download it without paying.
 */
final readonly class DownloadsHealth implements HealthCheck
{
    public function __construct(
        private DownloadService $downloads,
        private FileDelivery $delivery,
    ) {}

    public static function id(): string
    {
        return 'downloads';
    }

    public static function label(): string
    {
        return __('The shop’s download files', 'orillacart');
    }

    public function check(): HealthStatus
    {
        $storage = $this->downloads->storage();
        if ($storage->reachable === true) {
            return HealthStatus::critical(
                __('The web server hands out the shop’s download files', 'orillacart'),
                \sprintf(
                    /* translators: 1: the folder, 2: the web server's rule */
                    __('Anyone with a file’s address can download it without buying it. Move the folder (%1$s) outside the site by defining ORILLACART_DOWNLOADS_DIR, or deny it in the web server’s configuration: %2$s', 'orillacart'),
                    $storage->directory,
                    $storage->nginxRule,
                ),
            );
        }

        return HealthStatus::good(
            __('The shop’s download files are private', 'orillacart'),
            $storage->location === StorageCheck::OUTSIDE_WEB_ROOT ? __('They are kept outside the site.', 'orillacart') : __('The web server refuses to hand them out.', 'orillacart'),
        );
    }

    public function info(): array
    {
        $storage = $this->downloads->storage();

        return [
            'folder' => ['label' => __('Download files', 'orillacart'), 'value' => $storage->directory],
            'reachable' => ['label' => __('Reachable over the web', 'orillacart'), 'value' => match ($storage->reachable) {
                true => __('yes', 'orillacart'),
                false => __('no', 'orillacart'),
                null => __('not tested', 'orillacart'),
            }],
            'delivery' => ['label' => __('Sent by', 'orillacart'), 'value' => $this->delivery->settings()->method->value],
        ];
    }
}
