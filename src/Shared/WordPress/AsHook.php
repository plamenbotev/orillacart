<?php

declare(strict_types=1);

namespace OrillaCart\Shared\WordPress;

/**
 * Escape hatch for a WordPress hook no integration contract covers. Put the class in the area's
 * Infrastructure/WordPress/Hooks folder; when two such classes do the same kind of job, give that job a contract.
 *
 *     #[AsHook('wp_head')]
 *     #[AsHook('the_title', filter: true, method: 'title', arguments: 2)]
 *
 * The class is built only when the hook fires, by the container of the site the process is on. While another site
 * is switched to (switch_to_blog()), the hook runs on that site's shop, or not at all where it has none; $anySite
 * runs it whatever site the process is on, for what guards the process rather than a shop's data.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class AsHook
{
    public const string TAG = 'orillacart.hook';

    public function __construct(
        public string $hook,
        public string $method = '__invoke',
        public int $priority = 10,
        public int $arguments = 1,
        public bool $filter = false,
        public bool $anySite = false,
    ) {}
}
