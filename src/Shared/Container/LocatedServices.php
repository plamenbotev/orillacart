<?php

declare(strict_types=1);

namespace OrillaCart\Shared\Container;

use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * The classes of a service locator's services, by id. A compiled container knows them without building the
 * services; the uncompiled one a read-only plugin folder runs on in development knows only "?", so there each
 * service is built and asked.
 */
final class LocatedServices
{
    /**
     * @param ServiceLocator<mixed> $locator
     *
     * @return array<string, class-string> by id; a service that is no object is left out
     */
    public static function classes(ServiceLocator $locator): array
    {
        $classes = [];
        foreach ($locator->getProvidedServices() as $id => $type) {
            $type = ltrim($type, '?');
            if ($type !== '' && (class_exists($type) || interface_exists($type))) {
                $classes[(string) $id] = $type;
                continue;
            }
            $service = $locator->get((string) $id);
            if (\is_object($service)) {
                $classes[(string) $id] = $service::class;
            }
        }

        return $classes;
    }
}
