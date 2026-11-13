<?php

declare(strict_types=1);

namespace OrillaCart\Packages\Application\Adapters;

use OrillaCart\Packages\Application\Dto\LoadedPackage;
use OrillaCart\Packages\Application\ExtensionAssets;

/**
 * A block's `assets: ["tabs.js", "tabs.css"]` (design 73): its scripts and stylesheets, printed after its HTML, so
 * they load on the pages it is placed on, once each however many times it is. A script needs `assets: scripts`.
 */
final class Assets
{
    /**
     * @param array{props: array<string, mixed>} $declaration
     */
    public static function tags(?ExtensionAssets $assets, LoadedPackage $loaded, array $declaration): string
    {
        $names = $declaration['props']['assets'] ?? null;
        if ($assets === null || !\is_array($names)) {
            return '';
        }
        $tags = '';
        foreach ($names as $name) {
            if (\is_string($name) && (str_ends_with($name, '.js') || str_ends_with($name, '.css'))) {
                $tags .= $assets->tag($loaded->package->slug(), $name);
            }
        }

        return $tags;
    }
}
