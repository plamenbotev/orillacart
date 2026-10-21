<?php

declare(strict_types=1);

namespace OrillaCart\Platform\Health;

use OrillaCart\Shared\WordPress\HealthCheck;
use OrillaCart\Shared\WordPress\HealthStatus;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * The shop's checks (each a HealthCheck), for Site Health, the Status screen and `wp orillacart doctor`. A check
 * that breaks says so as its finding instead of breaking the caller.
 */
final readonly class ShopHealth
{
    /**
     * @param ServiceLocator<HealthCheck> $checks by id
     */
    public function __construct(
        #[AutowireLocator(HealthCheck::TAG, defaultIndexMethod: 'id')]
        private ServiceLocator $checks,
    ) {}

    /**
     * @return array<string, string> each check's label, by id
     */
    public function labels(): array
    {
        $labels = [];
        foreach ($this->checks->getProvidedServices() as $id => $class) {
            $class = ltrim($class, '?');
            $labels[$id] = is_subclass_of($class, HealthCheck::class) ? $class::label() : $id;
        }

        return $labels;
    }

    public function check(string $id): HealthStatus
    {
        try {
            return $this->checks->get($id)->check();
        } catch (\Throwable $e) {
            return HealthStatus::recommended(__('A shop check could not run', 'orillacart'), $e->getMessage());
        }
    }

    /**
     * Every check's finding, by id.
     *
     * @return array<string, HealthStatus>
     */
    public function checkAll(): array
    {
        $found = [];
        foreach (array_keys($this->labels()) as $id) {
            $found[$id] = $this->check($id);
        }

        return $found;
    }

    /**
     * What the checks know, for a support request: "<check>_<key>" => label and value.
     *
     * @return array<string, array{label: string, value: string}>
     */
    public function info(): array
    {
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

        return $fields;
    }
}
