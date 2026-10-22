<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Infrastructure\WordPress;

use OrillaCart\Forms\Application\FormUploads;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * A file a shopper sent with an item, for the shop manager from the order screen:
 * admin-post.php?action=orillacart_order_upload&order=12&upload=34 with the REST nonce the admin screens hold.
 * Only the order's own files; always as an attachment, never shown in the browser, which runs nothing of it.
 */
#[AsHook('admin_post_orillacart_order_upload', method: 'serve')]
final readonly class ServeOrderUpload
{
    public function __construct(private FormUploads $uploads) {}

    public function serve(): void
    {
        $nonce = $_GET['_wpnonce'] ?? '';
        if (!current_user_can(Capability::MANAGE_SHOP) || !\is_string($nonce) || wp_verify_nonce($nonce, 'wp_rest') === false) {
            wp_die(esc_html__('You may not see the files of orders.', 'orillacart'), esc_html__('File', 'orillacart'), ['response' => 403, 'back_link' => true]);
        }
        $order = $_GET['order'] ?? '';
        $upload = $_GET['upload'] ?? '';
        $file = \is_string($order) && ctype_digit($order) && \is_string($upload) && ctype_digit($upload) ? $this->uploads->file((int) $order, (int) $upload) : null;
        if ($file === null) {
            wp_die(esc_html__('This file is not here.', 'orillacart'), esc_html__('File', 'orillacart'), ['response' => 404, 'back_link' => true]);
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        foreach (self::headers($file['name'], $file['type'], (int) filesize($file['path'])) as $header) {
            header($header);
        }
        readfile($file['path']);
        exit;
    }

    /**
     * What the browser is told of the file: its stored type, to save it, never to sniff or run it.
     *
     * @return list<string>
     */
    public static function headers(string $name, string $type, int $size): array
    {
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);

        return [
            'Content-Type: ' . $type,
            \sprintf('Content-Disposition: attachment; filename="%s"; filename*=UTF-8\'\'%s', $ascii === '' ? 'file' : $ascii, rawurlencode($name)),
            'Content-Length: ' . $size,
            'X-Content-Type-Options: nosniff',
            "Content-Security-Policy: sandbox; default-src 'none'",
            'Cross-Origin-Resource-Policy: same-origin',
        ];
    }
}
