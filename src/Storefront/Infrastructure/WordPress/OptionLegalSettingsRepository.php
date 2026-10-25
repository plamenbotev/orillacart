<?php

declare(strict_types=1);

namespace OrillaCart\Storefront\Infrastructure\WordPress;

use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;
use OrillaCart\Storefront\Domain\LegalSettings;
use OrillaCart\Storefront\Domain\LegalSettingsRepository;
use OrillaCart\Storefront\Domain\OrderButton;
use OrillaCart\Storefront\Domain\StorefrontRuleViolation;

final readonly class OptionLegalSettingsRepository implements LegalSettingsRepository, LifecycleListener, RemovesShopData
{
    public const string OPTION = 'orillacart_legal';

    public function load(): LegalSettings
    {
        $stored = get_option(self::OPTION);
        if (!\is_array($stored)) {
            return new LegalSettings();
        }
        $page = static fn(string $key): ?int => \is_int($stored[$key] ?? null) && $stored[$key] > 0 ? $stored[$key] : null;
        $button = \is_string($stored['order_button'] ?? null) ? OrderButton::tryFrom($stored['order_button']) : null;
        try {
            return new LegalSettings($page('terms'), $page('withdrawal'), $page('delivery'), $page('legal_notice'), ($stored['accept_terms'] ?? false) === true, $button ?? OrderButton::PlaceOrder);
        } catch (StorefrontRuleViolation) {
            // The terms page went: checkout no longer asks for it.
            return new LegalSettings(null, $page('withdrawal'), $page('delivery'), $page('legal_notice'), false, $button ?? OrderButton::PlaceOrder);
        }
    }

    public function save(LegalSettings $settings): void
    {
        update_option(self::OPTION, [
            'terms' => $settings->terms,
            'withdrawal' => $settings->withdrawal,
            'delivery' => $settings->delivery,
            'legal_notice' => $settings->legalNotice,
            'accept_terms' => $settings->acceptTerms,
            'order_button' => $settings->orderButton->value,
        ], true);
    }

    public function isPage(int $id): bool
    {
        return get_post_type($id) === 'page' && !\in_array(get_post_status($id), [false, 'trash'], true);
    }

    public function makePage(string $title, string $content, bool $publish): int
    {
        $id = wp_insert_post([
            'post_type' => 'page',
            'post_status' => $publish ? 'publish' : 'draft',
            'post_title' => $title,
            'post_content' => $content,
            'comment_status' => 'closed',
        ], true);
        if ($id instanceof \WP_Error) {
            throw new \RuntimeException('The page could not be made: ' . $id->get_error_message());
        }

        return $id;
    }

    public function published(int $id): ?array
    {
        $page = get_post($id);
        if (!$page instanceof \WP_Post || $page->post_type !== 'page' || $page->post_status !== 'publish') {
            return null;
        }

        return ['title' => get_the_title($page), 'html' => wp_kses_post(do_blocks($page->post_content))];
    }

    public function activate(): void {}

    public function upgrade(?string $fromVersion): void {}

    public function deactivate(): void {}

    public function uninstall(): void
    {
        delete_option(self::OPTION);
    }
}
