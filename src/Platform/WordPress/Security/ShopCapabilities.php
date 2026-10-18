<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Security;

use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Security\Capability;

/**
 * Gives administrators the shop's capabilities and makes the Shop manager role; on uninstall takes the capabilities
 * from every role and removes the Shop manager role.
 *
 * A shop manager runs the shop and edits the site's content like an editor, but cannot change users, plugins or
 * WordPress settings. The old plugin's manager could edit any user, administrators included; that is not carried over.
 */
final readonly class ShopCapabilities implements LifecycleListener
{
    public const string SHOP_MANAGER = 'orillacart_shop_manager';

    public function activate(): void
    {
        $administrator = get_role('administrator');
        if ($administrator !== null) {
            foreach (Capability::all() as $capability) {
                $administrator->add_cap($capability);
            }
        }

        $manager = get_role(self::SHOP_MANAGER) ?? add_role(self::SHOP_MANAGER, 'Shop manager', ['read' => true]);
        if ($manager === null) {
            return;
        }
        // An editor's capabilities, or the least a manager needs where a site has removed the editor role.
        $editor = get_role('editor');
        $content = $editor === null ? ['read' => true, 'upload_files' => true] : $editor->capabilities;
        foreach (array_keys(array_filter($content)) as $capability) {
            $manager->add_cap($capability);
        }
        foreach (Capability::all() as $capability) {
            $manager->add_cap($capability);
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
        remove_role(self::SHOP_MANAGER);
    }
}
