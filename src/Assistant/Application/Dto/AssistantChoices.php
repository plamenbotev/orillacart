<?php

declare(strict_types=1);

namespace OrillaCart\Assistant\Application\Dto;

use OrillaCart\Shared\Http\Routing\Tool;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the owner chose for a key's assistant: which tools it is offered, how they are listed, and which risks
 * wait for the owner's yes.
 */
final readonly class AssistantChoices
{
    public const string ALL_TOOLS = 'all';
    public const string FIND_ON_DEMAND = 'find';

    /**
     * @param list<string> $offAreas the areas whose tools it is not offered ("Ordering", an add-on's name): an area
     *                               that comes later, with an update or an add-on, is offered
     * @param list<string> $off      tools left out by name, such as one of an add-on's
     * @param string            $list   all: every tool is listed; find: three tools find, describe and run them
     * @param list<string>      $ask    the risks (Tool::RISKS) that wait for the owner's yes: a risk that comes
     *                                  later, with an update, waits too until the owner unticks it
     */
    public function __construct(
        #[Assert\Count(max: 100)]
        #[Assert\All([new Assert\Length(max: 100)])]
        public array $offAreas = [],
        #[Assert\Count(max: 1000)]
        #[Assert\All([new Assert\Regex('/^[a-z][a-z0-9_]{0,63}$/')])]
        public array $off = [],
        #[Assert\Choice(choices: [self::ALL_TOOLS, self::FIND_ON_DEMAND])]
        public string $list = self::ALL_TOOLS,
        #[Assert\All([new Assert\Choice(choices: Tool::RISKS)])]
        public array $ask = Tool::RISKS,
    ) {}

    /**
     * The choices as stored; what is missing or not known is the default. A risk that was not there when they
     * were stored is asked about, as the owner never chose otherwise.
     *
     * @param array<array-key, mixed>|null $stored
     */
    public static function fromArray(?array $stored): self
    {
        $strings = static fn(mixed $values): array => \is_array($values) ? array_values(array_filter($values, \is_string(...))) : [];
        $stored ??= [];

        return new self(
            $strings($stored['offAreas'] ?? []),
            $strings($stored['off'] ?? []),
            ($stored['list'] ?? null) === self::FIND_ON_DEMAND ? self::FIND_ON_DEMAND : self::ALL_TOOLS,
            \is_array($stored['ask'] ?? null)
                ? array_values(array_filter(Tool::RISKS, static fn(string $risk): bool => \in_array($risk, $strings($stored['ask']), true) || !\in_array($risk, $strings($stored['risks'] ?? []), true)))
                : Tool::RISKS,
        );
    }

    /**
     * The choices to store, with the risks there were to choose from, so a later one is not taken as unticked.
     *
     * @return array{offAreas: list<string>, off: list<string>, list: string, ask: list<string>, risks: list<string>}
     */
    public function toArray(): array
    {
        return ['offAreas' => $this->offAreas, 'off' => $this->off, 'list' => $this->list, 'ask' => $this->ask, 'risks' => Tool::RISKS];
    }

    public function offers(ToolDefinition $tool): bool
    {
        return !\in_array($tool->area, $this->offAreas, true) && !\in_array($tool->name, $this->off, true);
    }

    public function asksBefore(?string $risk): bool
    {
        return $risk !== null && \in_array($risk, $this->ask, true);
    }
}
