<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Setup;

use OrillaCart\Platform\I18n\GettextTranslations;
use OrillaCart\Platform\Serialization\SerializerFactory;
use OrillaCart\Platform\Serialization\ValidatorFactory;
use OrillaCart\Settings\Application\SettingsArchive;
use OrillaCart\Setup\Application\Dto\ApplySetup;
use OrillaCart\Setup\Application\Dto\PlannedChange;
use OrillaCart\Setup\Application\SetupAnswers;
use OrillaCart\Setup\Application\SetupAnswersRepository;
use OrillaCart\Setup\Application\SetupService;
use OrillaCart\Setup\Application\SetupStep;
use OrillaCart\Setup\Domain\SetupStepNotFound;
use OrillaCart\Setup\Infrastructure\TaggedSetupSteps;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Http\Input\RequestMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints as Assert;

final class SetupServiceTest extends TestCase
{
    private FakeShop $shop;
    private SetupService $setup;
    private SetupAnswersRepository $answers;

    protected function setUp(): void
    {
        $this->shop = new FakeShop();
        $this->answers = new class implements SetupAnswersRepository {
            /** @var array<string, array<array-key, mixed>> */
            public array $stored = [];
            /** @var array<array-key, mixed>|null */
            public ?array $kept = null;

            public function load(): array
            {
                return $this->stored;
            }

            public function save(array $answers): void
            {
                $this->stored = $answers;
            }

            public function keepBefore(array $settings): void
            {
                $this->kept = $settings;
            }

            public function before(): ?array
            {
                return $this->kept;
            }
        };
        $archive = new class ($this->shop) implements SettingsArchive {
            public function __construct(private FakeShop $shop) {}

            public function export(): array
            {
                return ['format' => 'test', 'version' => '1', 'exported_at' => '', 'site' => '', 'settings' => $this->shop->names];
            }

            public function import(array $file): array
            {
                return ['imported' => [], 'refused' => []];
            }
        };
        $serializers = new SerializerFactory();
        $mapper = new RequestMapper($serializers->create(), new ValidatorFactory()->create(), $serializers->nameConverter(), new GettextTranslations());
        $this->setup = new SetupService(new TaggedSetupSteps([new NamesStep($this->shop, 20), new NamesStep($this->shop, 10, 'business')]), $this->answers, $mapper, $archive);
    }

    public function testAnswersAreKeptByStepAndRefusedWhenNotValid(): void
    {
        $before = $this->setup->answers();
        self::assertSame(['business' => null, 'names' => null], $before, 'by position');

        $after = $this->setup->answer('names', ['names' => ['shop' => 'Acme']]);
        self::assertSame(['names' => ['shop' => 'Acme']], $after['names']);

        try {
            $this->setup->answer('names', ['names' => ['shop' => '']]);
            self::fail('refused');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }
        self::assertSame(['names' => ['shop' => 'Acme']], $this->setup->answers()['names'], 'the valid answers stay');

        $this->expectException(SetupStepNotFound::class);
        $this->setup->answer('nothing', []);
    }

    public function testThePlanSaysWhatWouldChangeAndSavesNothing(): void
    {
        $this->setup->answer('names', ['names' => ['shop' => 'Acme', 'sender' => 'Acme Ltd']]);

        $plan = $this->setup->plan();

        self::assertSame(['names.shop', 'names.sender'], array_column($plan->changes, 'key'));
        self::assertSame('Call the shop "Acme".', (string) $plan->changes[0]['summary']);
        self::assertNull($plan->changes[0]['existing']);
        self::assertSame([], $this->shop->names);
    }

    public function testApplyingMakesThePlanAndKeepsWhatThereIsUnlessReplaced(): void
    {
        $this->shop->names = ['shop' => 'Old name'];
        $this->setup->answer('names', ['names' => ['shop' => 'Acme', 'sender' => 'Acme Ltd']]);
        $plan = $this->setup->plan();
        self::assertSame('It is called "Old name".', (string) $plan->changes[0]['existing']);

        $result = $this->setup->apply(new ApplySetup($plan->fingerprint));

        self::assertSame(['names.sender'], $result->applied);
        self::assertSame(['names.shop'], $result->kept);
        self::assertSame(['shop' => 'Old name', 'sender' => 'Acme Ltd'], $this->shop->names);
        self::assertSame(['shop' => 'Old name'], $this->answers->before()['settings'] ?? null, 'the settings from before');

        $again = $this->setup->plan();
        self::assertSame(['names.shop'], array_column($again->changes, 'key'), 'only what is still different');
        $this->setup->apply(new ApplySetup($again->fingerprint, ['names.shop']));
        self::assertSame('Acme', $this->shop->name('shop'));
        self::assertSame([], $this->setup->plan()->changes);
    }

    public function testAPlanTheShopHasMovedOnFromIsNotApplied(): void
    {
        $this->setup->answer('names', ['names' => ['shop' => 'Acme']]);
        $plan = $this->setup->plan();
        $this->shop->names['shop'] = 'Changed meanwhile';

        try {
            $this->setup->apply(new ApplySetup($plan->fingerprint));
            self::fail('refused');
        } catch (RuleViolation $e) {
            self::assertSame('orillacart_setup_plan_changed', $e->errorCode);
        }
        self::assertSame('Changed meanwhile', $this->shop->name('shop'));

        $this->expectExceptionMessage('The plan has no change "names.other" to replace with.');
        $this->setup->apply(new ApplySetup($this->setup->plan()->fingerprint, ['names.other']));
    }

    public function testApplyingStopsAtARefusedChangeAndApplyingAgainMakesTheRest(): void
    {
        $this->setup->answer('business', ['names' => ['refused' => 'x', 'owner' => 'Ann']]);
        $this->setup->answer('names', ['names' => ['shop' => 'Acme']]);

        $result = $this->setup->apply(new ApplySetup($this->setup->plan()->fingerprint));

        self::assertSame([], $result->applied);
        self::assertSame('business.refused', $result->refused['key'] ?? null);
        self::assertSame('Not this one.', (string) ($result->refused['reason'] ?? ''));
        self::assertSame([], $this->shop->names, 'nothing after it');
    }

    public function testAnswersThatNoLongerFitTheQuestionsCountAsNotGiven(): void
    {
        $this->answers->save(['names' => ['names' => ['shop' => ['not', 'text']]]]);

        self::assertSame([], $this->setup->plan()->changes);
    }
}

/** A step that sets names in the fake shop: one change per name that differs. */
final readonly class NamesStep implements SetupStep
{
    public function __construct(private FakeShop $shop, private int $position, private string $key = 'names') {}

    public function key(): string
    {
        return $this->key;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function answers(): string
    {
        return NamesAnswers::class;
    }

    public function plan(SetupAnswers $answers): array
    {
        $changes = [];
        foreach ($answers->of($this->key, NamesAnswers::class)->names ?? [] as $name => $value) {
            if (($this->shop->names[$name] ?? null) !== $value) {
                $existing = isset($this->shop->names[$name]) ? Text::of('It is called "%s".', $this->shop->names[$name]) : null;
                $changes[] = new PlannedChange("{$this->key}.{$name}", $this->key, Text::of('Call the %1$s "%2$s".', $name, $value), $existing, ['name' => $name, 'value' => $value]);
            }
        }

        return $changes;
    }

    public function apply(PlannedChange $change, SetupAnswers $answers): void
    {
        if ($change->data['name'] === 'refused') {
            throw new RuleViolation('Not this one.');
        }
        ['name' => $name, 'value' => $value] = $change->data;
        if (\is_string($name) && \is_string($value)) {
            $this->shop->names[$name] = $value;
        }
    }
}

final class FakeShop
{
    /** @var array<string, string> */
    public array $names = [];

    public function name(string $name): ?string
    {
        return $this->names[$name] ?? null;
    }
}

final readonly class NamesAnswers
{
    /** @param array<string, string> $names */
    public function __construct(
        #[Assert\All([new Assert\NotBlank(), new Assert\Type('string')])]
        public array $names = [],
    ) {}
}
