<?php

declare(strict_types=1);

namespace OrillaCart\Shared\WordPress;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A post type the shop registers. Put the class in the owning area's Infrastructure/WordPress/Content folder;
 * the ContentRegistrar registers it on init and refreshes rewrite rules when the plugin is activated or updated.
 */
#[AutoconfigureTag(self::TAG)]
interface PostTypeDefinition
{
    public const string TAG = 'orillacart.post_type';

    /**
     * At most 20 characters.
     *
     * @return lowercase-string&non-empty-string
     */
    public function name(): string;

    /**
     * Arguments for register_post_type(); the keys the shop's post types use.
     *
     * @return array{
     *   labels: array<string, string>,
     *   public: bool,
     *   show_ui?: bool,
     *   show_in_rest: bool,
     *   rest_base?: string,
     *   menu_icon?: string,
     *   menu_position?: int,
     *   supports: list<string>,
     *   has_archive: bool|string,
     *   rewrite: false|array{slug: string, with_front: bool},
     *   map_meta_cap: bool,
     *   capabilities: array<string, string>,
     * }
     */
    public function arguments(): array;
}
