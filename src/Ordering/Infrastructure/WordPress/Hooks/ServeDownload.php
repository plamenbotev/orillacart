<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Infrastructure\WordPress\Hooks;

use OrillaCart\Ordering\Application\Downloads;
use OrillaCart\Ordering\Domain\DownloadUnavailable;
use OrillaCart\Ordering\Infrastructure\WordPress\HomeOrderLinks;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * Sends a bought file to whoever follows its secret link (?orillacart_download=TOKEN). The file is read by PHP
 * from the private store: the web server never serves it by itself.
 */
#[AsHook('template_redirect', priority: 0)]
final readonly class ServeDownload
{
    public function __construct(private Downloads $downloads) {}

    public function __invoke(): void
    {
        $token = $_GET[HomeOrderLinks::DOWNLOAD_QUERY] ?? null;
        if (!\is_string($token)) {
            return;
        }

        try {
            $file = $this->downloads->open(sanitize_key($token));
        } catch (DownloadUnavailable $e) {
            wp_die(esc_html($e->getMessage()), esc_html__('Download not available', 'orillacart'), ['response' => 403, 'back_link' => true]);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: ' . $file->mimeType);
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $file->filename) . '"; filename*=UTF-8\'\'' . rawurlencode($file->filename));
        header('Content-Length: ' . (string) filesize($file->path));
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow');
        readfile($file->path);
        exit;
    }
}
