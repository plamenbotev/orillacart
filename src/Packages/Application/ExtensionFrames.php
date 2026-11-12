<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application;

/**
 * An extension's screen in the admin, in a frame of its own (design 73): its scripts run there with an origin of
 * their own, and reach the admin only through the bridge, whose every message the admin's side checks.
 */
interface ExtensionFrames
{
    /** Loads the admin's side of the frames on the screen being shown. */
    public function enqueue(): void;

    /**
     * The frame, ready to print.
     *
     * @param string $html  the screen, as the extension's template printed it (cleaned of inline code already)
     * @param string $title what the frame is called for a screen reader
     */
    public function render(string $extension, string $html, string $title): string;
}
