<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Security;

use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Security\Capability;

/**
 * Gives administrators the shop's capabilities, and takes them from every role on uninstall.
 */
final readonly class ShopCapabilities implements LifecycleListener
{
    public function activate(): void
    {
        $administrator = get_role('administrator');
        if ($administrator === null) {
            return;
        }
        foreach (Capability::all() as $capability) {
            $administrator->add_cap($capability);
        }
    }

    public function upgrade(?string $fromVersion): void
    {
        // A version may add capabilities; adding is idempotent.
        $this->activate();
    }

    public function deactivate(): void {}

    public function uninstall(): void
    {
        foreach (wp_roles()->role_objects as $role) {
            foreach (Capability::all() as $capability) {
                $role->remove_cap($capability);
            }
        }
    }
}
