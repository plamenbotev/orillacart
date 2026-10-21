<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Admin;

use OrillaCart\Platform\Health\ShopHealth;
use OrillaCart\Platform\WordPress\Registrar;
use OrillaCart\Shared\WordPress\HealthStatus;

/**
 * Adds the shop's checks to Tools → Site Health, each run as a direct test, and what they know to its Info tab
 * under "OrillaCart" (ShopHealth).
 */
final readonly class SiteHealthRegistrar implements Registrar
{
    private const string PREFIX = 'orillacart_';

    public function __construct(private ShopHealth $health) {}

    public function register(): void
    {
        add_filter('site_status_tests', fn(mixed $tests): mixed => $this->tests($tests));
        add_filter('debug_information', fn(mixed $info): mixed => $this->info($info));
    }

    private function tests(mixed $tests): mixed
    {
        if (!\is_array($tests)) {
            return $tests;
        }
        $direct = \is_array($tests['direct'] ?? null) ? $tests['direct'] : [];
        foreach ($this->health->labels() as $id => $label) {
            $direct[self::PREFIX . $id] = ['label' => $label, 'test' => fn(): array => $this->result($id)];
        }
        $tests['direct'] = $direct;

        return $tests;
    }

    /**
     * @return array{label: string, status: string, badge: array{label: string, color: string}, description: string, actions: string, test: string}
     */
    private function result(string $id): array
    {
        $status = $this->health->check($id);

        return [
            'label' => $status->label,
            'status' => $status->status,
            'badge' => ['label' => __('Shop', 'orillacart'), 'color' => $status->status === HealthStatus::GOOD ? 'blue' : ($status->status === HealthStatus::CRITICAL ? 'red' : 'orange')],
            'description' => $status->description === '' ? '' : '<p>' . esc_html($status->description) . '</p>',
            'actions' => $status->actionUrl === '' ? '' : \sprintf('<p><a href="%s">%s</a></p>', esc_url($status->actionUrl), esc_html($status->actionLabel)),
            'test' => self::PREFIX . $id,
        ];
    }

    private function info(mixed $info): mixed
    {
        if (!\is_array($info)) {
            return $info;
        }
        $fields = $this->health->info();
        $info['orillacart'] = ['label' => 'OrillaCart', 'fields' => $fields];

        return $info;
    }
}
