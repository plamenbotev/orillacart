<?php

declare(strict_types=1);

namespace OrillaCart\Integrations\Infrastructure\WordPress;

use OrillaCart\Integrations\Application\WebhookTopics;

/**
 * The shop's webhook topics, and those add-ons add on the filter orillacart_webhook_topics (topic => label); an
 * add-on then sends its events with do_action('orillacart_send_webhook', $topic, $data).
 */
final readonly class WordPressWebhookTopics implements WebhookTopics
{
    public function all(): array
    {
        $topics = [
            self::ORDER_PLACED => __('Order placed', 'orillacart'),
            self::ORDER_PAID => __('Order paid', 'orillacart'),
            self::ORDER_STATUS_CHANGED => __('Order status changed', 'orillacart'),
            self::ORDER_REFUNDED => __('Order refunded', 'orillacart'),
            self::ORDER_DELETED => __('Order deleted', 'orillacart'),
            self::PRODUCT_CHANGED => __('Product changed', 'orillacart'),
            self::STOCK_CHANGED => __('Stock changed', 'orillacart'),
        ];
        $added = apply_filters('orillacart_webhook_topics', []);
        foreach (\is_array($added) ? $added : [] as $topic => $label) {
            if (\is_string($topic) && preg_match('/^[a-z0-9_]+\.[a-z0-9_.]+$/', $topic) === 1 && !isset($topics[$topic]) && $topic !== self::PING && \is_string($label)) {
                $topics[$topic] = $label;
            }
        }

        return $topics;
    }
}
