<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure\WordPress\Hooks;

use OrillaCart\Assistant\Application\AssistantTools;
use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;
use OrillaCart\Assistant\Infrastructure\WordPress\WordPressSchemas;
use OrillaCart\Shared\WordPress\AsHook;

/**
 * The assistant's tools as WordPress abilities (WordPress 6.9 and later), in the category "orillacart", so that
 * WordPress's own AI features and its MCP Adapter reach the shop too: list_orders is orillacart/list-orders.
 * Abilities cannot ask the owner before a call, so a tool with a risk stays the MCP server's, where it can. A
 * call runs as the tool does through MCP: the same checks, the same change log.
 */
#[AsHook('wp_abilities_api_categories_init', method: 'category')]
#[AsHook('wp_abilities_api_init', method: 'abilities')]
final readonly class RegisterAbilities
{
    public const string CATEGORY = 'orillacart';

    public function __construct(private AssistantTools $tools) {}

    public function category(): void
    {
        if (\function_exists('wp_register_ability_category')) {
            wp_register_ability_category(self::CATEGORY, [
                'label' => __('Shop', 'orillacart'),
                'description' => __('Run the shop: orders, products, customers, stock, tax, shipping, payments, reports and settings.', 'orillacart'),
            ]);
        }
    }

    public function abilities(): void
    {
        if (!\function_exists('wp_register_ability')) {
            return;
        }
        foreach ($this->tools->all() as $tool) {
            if ($tool->risk !== null) {
                continue;
            }
            wp_register_ability(self::name($tool->name), [
                'label' => $tool->title,
                'description' => $tool->description,
                'category' => self::CATEGORY,
                'input_schema' => WordPressSchemas::of($tool->inputSchema),
                'execute_callback' => fn(mixed $input = null): mixed => $this->run($tool, $input),
                'permission_callback' => static fn(): bool => current_user_can($tool->capability),
                'meta' => [
                    'annotations' => ['readonly' => $tool->readOnly, 'destructive' => $tool->destructive, 'idempotent' => $tool->idempotent],
                    'show_in_rest' => true,
                    'mcp' => ['public' => true],
                ],
            ]);
        }
    }

    /**
     * list_orders: orillacart/list-orders, as ability names take lowercase letters, digits and dashes.
     *
     * @return lowercase-string&non-falsy-string
     */
    public static function name(string $tool): string
    {
        return self::CATEGORY . '/' . strtolower(str_replace('_', '-', $tool));
    }

    private function run(ToolDefinition $tool, mixed $input): mixed
    {
        $arguments = \is_array($input) ? array_filter($input, \is_string(...), ARRAY_FILTER_USE_KEY) : [];
        $outcome = $this->tools->call($tool->name, $arguments, new Caller(get_current_user_id(), null, false));
        if ($outcome === null) {
            return new \WP_Error('orillacart_tool_not_offered', __('This shop manager may not use that.', 'orillacart'), ['status' => 403]);
        }
        if ($outcome->isError()) {
            return new \WP_Error('orillacart_tool_refused', (string) $outcome->refusal, ['status' => 422, 'details' => $outcome->data]);
        }

        // Objects as arrays, as WordPress's REST answers and output checks take them.
        return json_decode((string) json_encode($outcome->data), true);
    }
}
