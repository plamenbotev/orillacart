<?php

declare(strict_types=1);

namespace OrillaCart\Platform\WordPress\Admin;

use OrillaCart\Platform\WordPress\Registrar;
use OrillaCart\Shared\WordPress\EditorPanel;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Loads each EditorPanel's script in the block editor, only for its post type.
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
