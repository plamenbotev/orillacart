<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\WordPress;

use OrillaCart\Assistant\Application\AssistantContext;
use OrillaCart\Integrations\Application\ViewNormalizer;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Setup\Application\ShopReadiness;
use OrillaCart\Storefront\Application\ShopOpening;

/**
 * The shop as WordPress and its settings describe it, for the assistant's server.
 */
final readonly class WordPressAssistantContext implements AssistantContext
{
    public function __construct(
        private SettingsService $settings,
        private ShopOpening $opening,
        private ShopReadiness $readiness,
        private ViewNormalizer $views,
    ) {}

    public function shopName(): string
    {
        $name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);

        return $name === '' ? 'OrillaCart' : $name;
    }

    public function version(): string
    {
        return \defined('ORILLACART_VERSION') && \is_string(\constant('ORILLACART_VERSION')) ? \constant('ORILLACART_VERSION') : 'dev';
    }

    public function overview(): array
    {
        $settings = $this->settings->current();

        return [
            'name' => $this->shopName(),
            'address' => home_url('/'),
            'country' => $settings->shopCountry,
            'currency' => $settings->currency,
            'catalog_only' => $settings->catalogOnly,
            'opening_soon' => $this->opening->opensSoon(),
            'readiness' => $this->views->normalize($this->readiness->report()),
            'admin' => admin_url('admin.php?page=orillacart'),
        ];
    }

    public function instructions(): string
    {
        $currency = $this->settings->current()->currency;
        $user = wp_get_current_user();
        $who = $user->exists() ? $user->display_name : '';

        return implode("\n", [
            \sprintf('You manage the online shop "%s" (%s), an OrillaCart shop on WordPress%s.', $this->shopName(), home_url('/'), $who === '' ? '' : ", acting as the shop manager {$who}"),
            "Its prices and amounts are in {$currency} unless a tool says another currency. Amounts are strings with up to four decimals, such as \"19.90\".",
            'Every change a tool makes is written into the shop\'s change log with your key, and the owner can see it.',
            'Before a change the owner did not ask for in so many words, say what you are about to do. Read before you change: most things have a tool to list or show them.',
            'A refusal says what was wrong, field by field: correct the call, do not guess around it.',
            'Long work (imports, exports, rebuilding the search index or reports) runs as a background job; check it with the job tools.',
        ]);
    }
}
