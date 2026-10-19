<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Admin;

use OrillaCart\Platform\WordPress\Registrar;
use OrillaCart\Shared\WordPress\HealthCheck;
use OrillaCart\Shared\WordPress\HealthStatus;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Adds the shop's checks to Tools → Site Health, each run as a direct test, and what they know to its Info tab
 * under "OrillaCart". A check that breaks says so as its finding instead of breaking the screen.
 */
final readonly class SiteHealthRegistrar implements Registrar
{
    private const string PREFIX = 'orillacart_';

    /**
     * @param ServiceLocator<HealthCheck> $checks by id
     */
    public function __construct(
        #[AutowireLocator(HealthCheck::TAG, defaultIndexMethod: 'id')]
        private ServiceLocator $checks,
    ) {}

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
        foreach ($this->checks->getProvidedServices() as $id => $class) {
            $class = ltrim($class, '?');
            $label = is_subclass_of($class, HealthCheck::class) ? $class::label() : $id;
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
        try {
            $status = $this->checks->get($id)->check();
        } catch (\Throwable $e) {
            $status = HealthStatus::recommended(__('A shop check could not run', 'orillacart'), $e->getMessage());
        }

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
        $fields = [];
        foreach (array_keys($this->checks->getProvidedServices()) as $id) {
            try {
                foreach ($this->checks->get($id)->info() as $key => $field) {
                    $fields[$id . '_' . $key] = $field;
                }
            } catch (\Throwable $e) {
                $fields[$id . '_error'] = ['label' => $id, 'value' => $e->getMessage()];
            }
        }
        $info['orillacart'] = ['label' => 'OrillaCart', 'fields' => $fields];

        return $info;
    }
}
