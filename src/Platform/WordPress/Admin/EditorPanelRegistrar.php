<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Admin;

use OrillaCart\Platform\WordPress\Registrar;
use OrillaCart\Shared\WordPress\EditorPanel;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Loads each EditorPanel's script on its post type's edit screen: in the block editor, or on the classic screen
 * (the Classic Editor plugin, or the block editor turned off for the type), where the script renders into boxes.
 */
final readonly class EditorPanelRegistrar implements Registrar
{
    /**
     * @param iterable<EditorPanel> $panels
     */
    public function __construct(
        #[AutowireIterator(EditorPanel::TAG)]
        private iterable $panels,
        private AdminScripts $scripts,
    ) {}

    public function register(): void
    {
        add_action('enqueue_block_editor_assets', $this->enqueue(...));
        add_action('admin_enqueue_scripts', $this->enqueueClassic(...));
    }

    private function enqueueClassic(): void
    {
        $screen = get_current_screen();
        if ($screen !== null && $screen->base === 'post' && !$screen->is_block_editor()) {
            $this->enqueue();
        }
    }

    private function enqueue(): void
    {
        $screen = get_current_screen();
        if ($screen === null) {
            return;
        }
        foreach ($this->panels as $panel) {
            if ($panel->postType() === $screen->post_type) {
                $this->scripts->enqueue($panel->script());
            }
        }
    }
}
