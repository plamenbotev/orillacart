<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Infrastructure\WordPress\Hooks;

use OrillaCart\Cart\Application\CartService;
use OrillaCart\Customers\Application\PersonalData;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * The shop's part in WordPress's privacy tools: Tools → Export Personal Data gives out a person's orders and
 * addresses, Tools → Erase Personal Data anonymises their orders and forgets the rest, and the privacy policy
 * guide gets a suggested section.
 */
#[AsHook('wp_privacy_personal_data_exporters', method: 'exporters', filter: true)]
#[AsHook('wp_privacy_personal_data_erasers', method: 'erasers', filter: true)]
#[AsHook('admin_init', method: 'policy')]
final readonly class PrivacyTools
{
    public function __construct(private PersonalData $data) {}

    /**
     * @param array<string, mixed> $exporters
     *
     * @return array<string, mixed>
     */
    public function exporters(mixed $exporters): array
    {
        $exporters = \is_array($exporters) ? $exporters : [];
        $exporters['orillacart'] = [
            'exporter_friendly_name' => __('Shop orders and addresses', 'orillacart'),
            'callback' => function (string $email, int $page = 1): array {
                $result = $this->data->export($email, max(1, $page));

                return [
                    'data' => array_map(static fn(array $item): array => ['group_id' => $item['group'], 'group_label' => $item['label'], 'item_id' => $item['id'], 'data' => $item['data']], $result['items']),
                    'done' => $result['done'],
                ];
            },
        ];

        return $exporters;
    }

    /**
     * @param array<string, mixed> $erasers
     *
     * @return array<string, mixed>
     */
    public function erasers(mixed $erasers): array
    {
        $erasers = \is_array($erasers) ? $erasers : [];
        $erasers['orillacart'] = [
            'eraser_friendly_name' => __('Shop orders and addresses', 'orillacart'),
            'callback' => function (string $email, int $page = 1): array {
                $result = $this->data->erase($email, max(1, $page));

                return [
                    'items_removed' => $result['removed'],
                    'items_retained' => $result['retained'],
                    'messages' => $result['retained'] ? [__('Orders were kept for the shop\'s books, with the customer\'s details erased from them.', 'orillacart')] : [],
                    'done' => $result['done'],
                ];
            },
        ];

        return $erasers;
    }

    public function policy(): void
    {
        if (!\function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content('OrillaCart', wp_kses_post(
            '<p>' . __('When you place an order we keep your name, email address, billing and shipping addresses, phone number, VAT number if given, and what you bought. We use them to deliver the order, to send you emails about it, and to keep the accounts the law requires.', 'orillacart') . '</p>'
            . '<p>' . __('Payments are taken by the payment provider you choose; we keep its transaction reference, never your card details.', 'orillacart') . '</p>'
            . '<p>' . __('We keep orders and invoices for as long as tax law requires. If you ask us to erase your data, your orders stay in our books with your name, contact details and address removed; invoices already issued keep the name and address they were issued to, as tax law requires of them.', 'orillacart') . '</p>'
            . '<p>' . \sprintf(
                /* translators: %d: number of days */
                __('A cookie remembers your cart for %d days.', 'orillacart'),
                new \DateInterval(CartService::KEEP_FOR)->d,
            ) . '</p>'
            . '<p>' . __('If you give your email at checkout and do not finish, we may remind you of your cart once, if you agreed to it; you can say no at checkout or in your account.', 'orillacart') . '</p>'
            . '<p>' . __('If you sign up for our newsletter we keep your email address, when you signed up and what you agreed to, and may pass them to the mailing list service we use. You can unsubscribe at any time, from any newsletter or in your account.', 'orillacart') . '</p>'
            . '<p>' . __('If you ask to be told when a product is back in stock, we keep your email address until we have told you, or for half a year.', 'orillacart') . '</p>'
            . '<p>' . __('Files you upload with an order, and tax exemption certificates you send, are kept privately with the order or your account, for as long as the order or the exemption needs them.', 'orillacart') . '</p>'
            . '<p>' . __('Gift cards keep the name, email address and message of the person they are for.', 'orillacart') . '</p>',
        ));
    }
}
