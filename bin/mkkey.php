<?php

declare(strict_types=1);

/*
 * Mints an API key for an AI assistant or a script to try the REST API and the MCP endpoint with, under the first
 * administrator's name, and prints it as JSON (the secret is shown this once). Development only; run inside
 * wp-env, with the scope ("read" or "read_write", the default) after the file:
 *
 *   npx wp-env run cli --env-cwd=wp-content/plugins/orillacart wp eval-file bin/mkkey.php read_write
 */

use OrillaCart\Integrations\Application\ApiKeys;
use OrillaCart\Integrations\Application\Dto\CreateApiKey;
use OrillaCart\Platform\Kernel;
use OrillaCart\Platform\PluginContext;

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
$admin = (int) ($admins[0] ?? 0);
if ($admin === 0) {
    WP_CLI::error('The site has no administrator to own the key.');
}

$keys = new Kernel(new PluginContext(dirname(__DIR__) . '/orillacart.php', ORILLACART_VERSION))->container()->get(ApiKeys::class);
assert($keys instanceof ApiKeys);
$created = $keys->create(new CreateApiKey('MCP dev', $args[0] ?? 'read_write'), $admin);

WP_CLI::line((string) wp_json_encode($created, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
