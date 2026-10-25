<?php

declare(strict_types=1);

namespace OrillaCart\Extension;

/**
 * A payment gateway or shipping method whose settings can be tried at the provider: the shop manager's "Test"
 * button, on its screen and in the setup, asks only when pressed (version 14).
 */
interface TestsSettings
{
    /**
     * Asks the provider whether these settings work, such as by getting a token with the keys.
     *
     * @return string|null null when they work; otherwise what is wrong, in words for the shop manager
     */
    public function test(Settings $settings): ?string;
}
