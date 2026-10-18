<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Files;

/**
 * How private files reach the browser: read by PHP, or handed to the web server (X-Sendfile, X-Accel-Redirect),
 * which frees PHP during long downloads. A method is kept only after a test file went through it.
 */
interface FileDelivery
{
    public function settings(): DeliverySettings;

    /**
     * Sends a test file through the method and keeps the method when the file arrived; the settings stay as they
     * were when it did not.
     */
    public function change(DeliverySettings $settings): DeliveryCheck;

    /**
     * The header that hands the stored file to the web server, such as "X-Sendfile: /path"; null when PHP sends it.
     */
    public function handOff(string $key): ?string;
}
