<?php

declare(strict_types=1);

namespace OrillaCart\Setup\Application\Step;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Setup\Application\AskedByCountry;
use OrillaCart\Setup\Application\Dto\PlannedChange;
use OrillaCart\Setup\Application\Dto\SellingAnswers;
use OrillaCart\Setup\Application\SetupAnswers;
use OrillaCart\Setup\Application\SetupStep;
use OrillaCart\Setup\Domain\Regions;
use OrillaCart\Shared\Domain\Text;

/**
 * Who and where the shop sells to: consumers, businesses or both, and the countries checkout takes addresses in.
 */
final readonly class SellingStep implements SetupStep, AskedByCountry
{
    public const string KEY = 'selling';
    private const string COUNTRIES = 'selling.countries';

    public function __construct(
        private SettingsService $settings,
        private GeographyService $geography,
        private ShopFacts $facts,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function position(): int
    {
        return 20;
    }

    public function answers(): string
    {
        return SellingAnswers::class;
    }

    public function plan(SetupAnswers $answers): array
    {
        $selling = $answers->of(self::KEY, SellingAnswers::class);
        if ($selling === null) {
            return [];
        }
        $countries = $this->countries($selling, $this->facts->country($answers));
        $current = $this->settings->current()->sellTo;
        $sorted = $countries;
        sort($sorted);
        sort($current);
        if ($sorted === $current) {
            return [];
        }

        return [new PlannedChange(self::COUNTRIES, self::KEY, $this->summary($selling, $countries), data: ['countries' => $countries])];
    }

    public function apply(PlannedChange $change, SetupAnswers $answers): void
    {
        $countries = $change->data['countries'] ?? [];
        $this->settings->sellTo(array_values(array_filter(\is_array($countries) ? $countries : [], \is_string(...))));
    }

    /** @return list<string> empty for everywhere */
    private function countries(SellingAnswers $selling, ?string $home): array
    {
        return match (Regions::from($selling->regions)) {
            Regions::Home => $home === null ? [] : [$home],
            Regions::Eu => array_values(array_map(static fn($country): string => $country->code, array_filter($this->geography->countries(), static fn($country): bool => $country->inEu))),
            Regions::Europe => Regions::EUROPE,
            Regions::World => [],
            Regions::Listed => array_values(array_unique([...($home === null ? [] : [$home]), ...$selling->countries])),
        };
    }

    /** @param list<string> $countries */
    private function summary(SellingAnswers $selling, array $countries): Text
    {
        $who = match ($selling->customers) {
            SellingAnswers::CONSUMERS => Text::of('consumers'),
            SellingAnswers::BUSINESSES => Text::of('businesses'),
            default => Text::of('consumers and businesses'),
        };
        if ($countries === []) {
            return Text::of('The shop sells to %s everywhere.', $who);
        }
        if (\count($countries) > 5) {
            return Text::of('The shop sells to %1$s in %2$d countries.', $who, \count($countries));
        }
        $names = [];
        foreach ($this->geography->countries() as $country) {
            if (\in_array($country->code, $countries, true)) {
                $names[] = $country->name;
            }
        }

        return Text::of('The shop sells to %1$s in %2$s.', $who, implode(', ', $names));
    }
}
