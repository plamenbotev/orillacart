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
    /** The owner answers on the shop's own page, logged in: the yes is theirs, whatever the app says. */
    public const string ON_PAGE = 'page';
    /** The owner answers in the app's own window (MCP elicitation): the app reports the answer, and is trusted to. */
    public const string IN_APP = 'app';

    /**
     * The risks a yes on the shop's page may cover for one tool for a while: those whose calls are much alike, such
     * as saving one setting after another. Never money, deleting, customers or the shop opening, where each call is
     * its own and a standing yes is what text steering the model would use.
     */
    public const array ALLOWABLE = [Tool::CHANGES, Tool::PUBLISHING];

    /** How long such a yes may last, in minutes: 0 offers none. */
    public const array ALLOW_MINUTES = [0, 15, 60, 240];

    /**
     * @param list<string> $offAreas the areas whose tools it is not offered ("Ordering", an add-on's name): an area
     *                               that comes later, with an update or an add-on, is offered
     * @param list<string> $off      tools left out by name, such as one of an add-on's
     * @param string            $list   all: every tool is listed; find: three tools find, describe and run them
     * @param list<string>      $ask    the risks (Tool::RISKS) that wait for the owner's yes: a risk that comes
     *                                  later, with an update, waits too until the owner unticks it
     * @param string            $answer where the owner answers: on the shop's page (the default: an app that holds
     *                                  the key could answer its own question), or in the app's window
     * @param int               $allowMinutes for how long a yes on the shop's page may also cover the tool's later
     *                                  calls, for the ALLOWABLE risks (one of ALLOW_MINUTES; 0 never offers it)
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
        #[Assert\Choice(choices: [self::ON_PAGE, self::IN_APP])]
        public string $answer = self::ON_PAGE,
        #[Assert\Choice(choices: self::ALLOW_MINUTES)]
        public int $allowMinutes = 60,
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
            ($stored['answer'] ?? null) === self::IN_APP ? self::IN_APP : self::ON_PAGE,
            \in_array($stored['allowMinutes'] ?? null, self::ALLOW_MINUTES, true) ? $stored['allowMinutes'] : 60,
        );
    }

    /**
     * The choices to store, with the risks there were to choose from, so a later one is not taken as unticked.
     *
     * @return array{offAreas: list<string>, off: list<string>, list: string, ask: list<string>, risks: list<string>, answer: string, allowMinutes: int}
     */
    public function toArray(): array
    {
        return ['offAreas' => $this->offAreas, 'off' => $this->off, 'list' => $this->list, 'ask' => $this->ask, 'risks' => Tool::RISKS, 'answer' => $this->answer, 'allowMinutes' => $this->allowMinutes];
    }

    public function offers(ToolDefinition $tool): bool
    {
        return !\in_array($tool->area, $this->offAreas, true) && !\in_array($tool->name, $this->off, true);
    }

    public function asksBefore(?string $risk): bool
    {
        return $risk !== null && \in_array($risk, $this->ask, true);
    }

    /** For how many minutes the owner's yes on the page may cover a tool of this risk: 0 when it covers the one call. */
    public function allowsFor(?string $risk): int
    {
        return \in_array($risk, self::ALLOWABLE, true) ? $this->allowMinutes : 0;
    }
}
