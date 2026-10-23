<?php

declare(strict_types=1);

namespace OrillaCart\Tax\Infrastructure\WordPress;

use OrillaCart\Forms\Infrastructure\WordPress\ServeOrderUpload;
use OrillaCart\Shared\Security\Capability;
use OrillaCart\Shared\WordPress\AsHook;
use OrillaCart\Tax\Application\ExemptionCertificates;

/**
 * The copy of a certificate a customer sent, for the shop manager from the customer's page:
 * admin-post.php?action=orillacart_exemption_file&customer=12&exemption=34 with the REST nonce the admin screens
 * hold. Always as an attachment, never shown in the browser, which runs nothing of it.
 */
#[AsHook('admin_post_orillacart_exemption_file', method: 'serve')]
final readonly class ServeExemptionFile
{
    public function __construct(private ExemptionCertificates $certificates) {}

    public function serve(): void
    {
        $nonce = $_GET['_wpnonce'] ?? '';
        if (!current_user_can(Capability::MANAGE_SHOP) || !\is_string($nonce) || wp_verify_nonce($nonce, 'wp_rest') === false) {
            wp_die(esc_html__('You may not see customers’ certificates.', 'orillacart'), esc_html__('File', 'orillacart'), ['response' => 403, 'back_link' => true]);
        }
        $customer = $_GET['customer'] ?? '';
        $exemption = $_GET['exemption'] ?? '';
        $file = \is_string($customer) && ctype_digit($customer) && \is_string($exemption) && ctype_digit($exemption) ? $this->certificates->file((int) $customer, (int) $exemption) : null;
        if ($file === null) {
            wp_die(esc_html__('This file is not here.', 'orillacart'), esc_html__('File', 'orillacart'), ['response' => 404, 'back_link' => true]);
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        foreach (ServeOrderUpload::headers($file['name'], $file['type'], (int) filesize($file['path'])) as $header) {
            header($header);
        }
        readfile($file['path']);
        exit;
    }
}
