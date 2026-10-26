<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Infrastructure;

use OrillaCart\Assistant\Application\Dto\Caller;
use OrillaCart\Assistant\Application\Dto\RouteTool;
use OrillaCart\Assistant\Application\Dto\ToolDefinition;
use OrillaCart\Assistant\Application\Dto\ToolOutcome;
use OrillaCart\Assistant\Application\InputSchemas;
use OrillaCart\Assistant\Application\ToolSource;
use OrillaCart\Setup\Application\SetupStep;
use OrillaCart\Setup\Application\SetupSteps;
use OrillaCart\Shared\Http\Routing\RouteInputs;
use OrillaCart\Shared\Security\Capability;

/**
 * The setup's steps as tools, one each, setup_answer_business and so on, with the step's own questions as the
 * schema: add-ons' steps too. Each saves that step's answers through the setup's route, as the setup screen
 * does; nothing in the shop changes until apply_setup applies the plan plan_setup showed.
 */
final readonly class SetupToolSource implements ToolSource
{
    public const string PREFIX = 'setup_answer_';
    private const string PATH = '/setup/answers/{step}';

    public function __construct(
        private SetupSteps $steps,
        private RouteCaller $caller,
    ) {}

    public function tools(): array
    {
        $steps = $this->steps->all();
        $tools = [];
        foreach ($steps as $index => $step) {
            $tools[] = new ToolDefinition(
                self::PREFIX . $step->key(),
                'Answer the setup: ' . str_replace('_', ' ', $step->key()),
                self::description($step, $index + 1, \count($steps)),
                InputSchemas::forRoute('/', [], $step->answers()),
                false,
                false,
                true,
                null,
                'Setup',
                Capability::MANAGE_SHOP,
            );
        }

        return $tools;
    }

    public function run(ToolDefinition $tool, array $arguments, Caller $caller): ToolOutcome
    {
        $key = substr($tool->name, \strlen(self::PREFIX));
        $route = new RouteTool($tool->name, $tool->title, $tool->description, 'Setup', 'PUT', self::PATH, [], 'body', $tool->inputSchema, false, false, true, null, Capability::MANAGE_SHOP);

        return $this->caller->call($route, $arguments, ['step' => $key]);
    }

    private static function description(SetupStep $step, int $position, int $count): string
    {
        $about = RouteInputs::summary($step::class);

        return trim(($about === '' ? '' : $about . ' ') . "Step {$position} of {$count} of the setup. Saves only this step's answers, as the setup screen does, and answers with them; nothing in the shop changes until apply_setup. Call plan_setup to see what the answers would do, and show_country_profile for what the shop's country asks.");
    }
}
