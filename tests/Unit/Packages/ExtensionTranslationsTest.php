<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Packages;

use OrillaCart\Geography\Application\GeographyService;
use OrillaCart\Packages\Application\Dto\ExtensionWord;
use OrillaCart\Packages\Application\Dto\LoadedPackage;
use OrillaCart\Packages\Application\Dto\PackageManifest;
use OrillaCart\Packages\Application\ExtensionTranslations;
use OrillaCart\Packages\Application\ExtensionWords;
use OrillaCart\Packages\Application\ExtensionWordTypes;
use OrillaCart\Packages\Application\Host\TextFunctions;
use OrillaCart\Packages\Application\PackageTemplates;
use OrillaCart\Packages\Application\PackageTranslations;
use OrillaCart\Packages\Domain\Package;
use OrillaCart\Packages\Domain\PackageRepository;
use OrillaCart\Packages\Domain\PackageRuleViolation;
use OrillaCart\Platform\Twig\ExtensionWordsExtension;
use OrillaCart\Platform\Twig\PackageTemplateLoader;
use OrillaCart\Platform\Twig\ShopExtension;
use OrillaCart\Platform\Twig\StorefrontSandbox;
use OrillaCart\Platform\Twig\TwigTemplateWords;
use OrillaCart\Scripting\Application\Compiler;
use OrillaCart\Scripting\Application\Dto\Grant;
use OrillaCart\Scripting\Application\Host\Builtins;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Settings\Domain\DimensionUnit;
use OrillaCart\Settings\Domain\StoreSettings;
use OrillaCart\Settings\Domain\WeightUnit;
use OrillaCart\Shared\Domain\Currency;
use OrillaCart\Shared\Domain\Text;
use OrillaCart\Shared\I18n\Language;
use OrillaCart\Shared\I18n\PluralForms;
use OrillaCart\Shared\I18n\Translations;
use OrillaCart\Tests\Fixtures\FakeLanguages;
use OrillaCart\Tests\Fixtures\Geography\InMemoryCountryRepository;
use OrillaCart\Tests\Fixtures\I18n\FakeContentLanguages;
use OrillaCart\Tests\Fixtures\RecordingDispatcher;
use OrillaCart\Tests\Fixtures\Scripting\InMemoryScriptStore;
use OrillaCart\Tests\Fixtures\Settings\InMemorySettingsRepository;
use OrillaCart\Tests\Fixtures\Translations\InMemoryTranslations;
use OrillaCart\Translations\Application\ContentTranslations;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Extension\SandboxExtension;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;

/**
 * Design 57: an extension's words in the language shown, from its own translations, else the shop's, else as
 * written; listed from its declarations, scripts and templates; saved with their plural forms.
 */
final class ExtensionTranslationsTest extends TestCase
{
    private const string SCRIPT = <<<'OCS'
        extension "greeter" { name: "Greeter" }

        settings {
          greeting: text { label: "Greeting", help: "What it says" }
          tone: choice { label: "Tone", of: { warm: "Warm", cold: "Cold" } }
        }

        order_column "tickets" { label: "Tickets" fn value(order) { return "" } }

        fields on product { lead_time: number { label: "Lead time", help: "Days" } }

        fn hello(name) {
          return __("Hello %s")|replace({"%s": name})
        }

        fn items(count) {
          return _n("%d item", "%d items", count)|replace({"%d": count})
        }

        fn post() {
          return _x("Post", "a verb") ~ " " ~ __("Free shipping") ~ " " ~ __("Greeting")
        }

        fn where() { return language() }
        OCS;

    private InMemoryTranslations $records;
    private FakeLanguages $locale;
    private Package $package;
    private ExtensionTranslations $translations;
    private Scripts $scripts;
    private PackageRepository $repository;

    protected function setUp(): void
    {
        $this->records = new InMemoryTranslations()->add(new ExtensionWordTypes());
        $this->locale = new FakeLanguages('en_US');
        $content = new ContentTranslations($this->records, $this->records, new FakeContentLanguages([new Language('en', 'en_US', 'English'), new Language('de', 'de_DE', 'Deutsch'), new Language('pl', 'pl_PL', 'Polski')]), $this->locale);
        $this->package = new Package('greeter', 'Greeter', new \DateTimeImmutable('2026-11-09'));
        new \ReflectionProperty(Package::class, 'id')->setValue($this->package, 7);
        $this->package->write('main.ocs', self::SCRIPT . "\n", new \DateTimeImmutable('2026-11-09'));
        $this->package->write('templates/note.html.twig', "<p>{{ __('Thanks') }}</p>\n<p>{{ _n('%d day', '%d days', days) }}</p>\n", new \DateTimeImmutable('2026-11-09'));
        $package = $this->package;
        $this->repository = new class ($package) implements PackageRepository {
            public function __construct(private readonly Package $package) {}

            public function all(): array
            {
                return [$this->package];
            }

            public function enabled(): array
            {
                return [$this->package];
            }

            public function find(string $slug): ?Package
            {
                return $slug === $this->package->slug() ? $this->package : null;
            }

            public function save(Package $package): void {}

            public function remove(Package $package): void {}
        };
        $this->translations = new ExtensionTranslations($this->repository, $content, self::shop($this->locale), $this->locale);
        $catalog = HostCatalog::of([...new Builtins()->functions(), ...new TextFunctions($this->translations)->functions()]);
        $this->scripts = new Scripts(new Compiler($catalog), $catalog, new InMemoryScriptStore());
    }

    public function testPluralRulesFollowTheLanguage(): void
    {
        self::assertSame([2, 0, 1, 1], [PluralForms::count('en_US'), PluralForms::index('en_US', 1), PluralForms::index('en_US', 0), PluralForms::index('en_US', 5)]);
        self::assertSame([0, 0, 1], [PluralForms::index('fr_FR', 0), PluralForms::index('fr_FR', 1), PluralForms::index('fr_FR', 2)], 'French counts 0 as one');
        self::assertSame([3, 0, 1, 2, 1, 2], [PluralForms::count('pl_PL'), PluralForms::index('pl_PL', 1), PluralForms::index('pl_PL', 3), PluralForms::index('pl_PL', 5), PluralForms::index('pl_PL', 22), PluralForms::index('pl_PL', 12)]);
        self::assertSame([0, 2, 1], [PluralForms::index('ru_RU', 21), PluralForms::index('ru_RU', 11), PluralForms::index('uk', 34)]);
        self::assertSame([6, 0, 1, 2, 3, 4, 5], [PluralForms::count('ar'), PluralForms::index('ar', 0), PluralForms::index('ar', 1), PluralForms::index('ar', 2), PluralForms::index('ar', 7), PluralForms::index('ar', 11), PluralForms::index('ar', 100)]);
        self::assertSame([0, 1], [PluralForms::index('pt_BR', 0), PluralForms::index('pt_PT', 0)], 'Brazil\'s Portuguese counts 0 as one, Portugal\'s does not');
        self::assertSame([1, 0], [PluralForms::count('ja'), PluralForms::index('ja', 9)]);
        self::assertSame(2, PluralForms::count('xx_YY'), 'a language not listed takes English\'s');
    }

    public function testTheWordsMustBeWrittenInTheCall(): void
    {
        $compiled = $this->scripts->compile("fn f(name) { return __(name) ~ _n(\"a\", name, 2) ~ _x(\"a\", name) }\n", 'refused');

        self::assertSame([
            '"__" takes the words themselves, written as a string, so they can be listed for translators: put the values in after, as __("%s left")|replace({"%s": count})',
            '"_n" takes the words themselves, written as a string, so they can be listed for translators: put the values in after, as __("%s left")|replace({"%s": count})',
            '"_x" takes the words themselves, written as a string, so they can be listed for translators: put the values in after, as __("%s left")|replace({"%s": count})',
        ], array_map(static fn($finding): string => $finding->message, $compiled->findings));
        self::assertSame([
            ['text' => 'Hello %s', 'plural' => null, 'context' => '', 'line' => 13],
            ['text' => '%d item', 'plural' => '%d items', 'context' => '', 'line' => 17],
            ['text' => 'Post', 'plural' => null, 'context' => 'a verb', 'line' => 21],
            ['text' => 'Free shipping', 'plural' => null, 'context' => '', 'line' => 21],
            ['text' => 'Greeting', 'plural' => null, 'context' => '', 'line' => 21],
        ], $this->scripts->compile(self::SCRIPT . "\n", 'words')->words);
    }

    public function testTheWordsAreListedFromDeclarationsScriptsAndTemplatesOnceEach(): void
    {
        $words = new ExtensionWords($this->scripts, new TwigTemplateWords(self::twig($this->translations)))->of($this->package);

        self::assertSame([
            ['Greeter', null, '', ['extension: name']],
            ['Greeting', null, '', ['settings: greeting, label', 'main.ocs, line 21']],
            ['What it says', null, '', ['settings: greeting, help']],
            ['Tone', null, '', ['settings: tone, label']],
            ['Warm', null, '', ['settings: tone, choices']],
            ['Cold', null, '', ['settings: tone, choices']],
            ['Tickets', null, '', ['order_column tickets: label']],
            ['Lead time', null, '', ['fields on product: lead_time, label']],
            ['Days', null, '', ['fields on product: lead_time, help']],
            ['Hello %s', null, '', ['main.ocs, line 13']],
            ['%d item', '%d items', '', ['main.ocs, line 17']],
            ['Post', null, 'a verb', ['main.ocs, line 21']],
            ['Free shipping', null, '', ['main.ocs, line 21']],
            ['Thanks', null, '', ['templates/note.html.twig, line 1']],
            ['%d day', '%d days', '', ['templates/note.html.twig, line 2']],
        ], array_map(static fn(ExtensionWord $word): array => [$word->text, $word->plural, $word->context, $word->where], $words));
        self::assertNotSame(ExtensionWord::keyOf('Post'), ExtensionWord::keyOf('Post', null, 'a verb'), 'a context is another word');
    }

    public function testAScriptsWordsAreTheExtensionsTranslationThenTheShopsThenAsWritten(): void
    {
        $this->records->replace(ExtensionWordTypes::TYPE, 7, [
            ExtensionWord::field(ExtensionWord::keyOf('Hello %s')) => ['de_DE' => 'Hallo %s'],
            ExtensionWord::field(ExtensionWord::keyOf('Post', null, 'a verb')) => ['de_DE' => 'Senden'],
            ExtensionWord::field(ExtensionWord::keyOf('Greeting')) => ['de_DE' => 'Gruß'],
            ExtensionWord::field(ExtensionWord::keyOf('%d item', '%d items')) => ['pl_PL' => '%d produkt'],
            ExtensionWord::field(ExtensionWord::keyOf('%d item', '%d items'), 1) => ['pl_PL' => '%d produkty'],
            ExtensionWord::field(ExtensionWord::keyOf('%d item', '%d items'), 2) => ['pl_PL' => '%d produktów'],
        ]);
        $script = $this->scripts->load(self::SCRIPT . "\n", 'greeter', grant: new Grant('greeter'));

        self::assertSame(['Hello Ana', '1 item', '5 items', 'Post Free shipping Greeting', 'en_US'], [$script->run('fn_hello', 'Ana'), $script->run('fn_items', 1), $script->run('fn_items', 5), $script->run('fn_post'), $script->run('fn_where')], 'the default language: as written');

        $this->locale->locale = 'de_DE';
        self::assertSame(['Hallo Ana', 'Senden Kostenloser Versand Gruß', '5 Stück', 'de_DE'], [$script->run('fn_hello', 'Ana'), $script->run('fn_post'), $script->run('fn_items', 5), $script->run('fn_where')], 'the extension\'s, then the shop\'s for its own words');

        $this->locale->locale = 'pl_PL';
        self::assertSame(['1 produkt', '3 produkty', '5 produktów', '22 produkty'], [$script->run('fn_items', 1), $script->run('fn_items', 3), $script->run('fn_items', 5), $script->run('fn_items', 22)], 'Polish has three forms');
        $this->records->replace(ExtensionWordTypes::TYPE, 7, [ExtensionWord::field(ExtensionWord::keyOf('%d item', '%d items'), 2) => []]);
        $this->translations->changed('greeter');
        self::assertSame('5 produkty', $script->run('fn_items', 5), 'a form left empty takes the nearest filled one before it');
    }

    public function testDeclaredWordsAreShownTranslated(): void
    {
        $this->records->replace(ExtensionWordTypes::TYPE, 7, [
            ExtensionWord::field(ExtensionWord::keyOf('Greeting')) => ['de_DE' => 'Gruß'],
            ExtensionWord::field(ExtensionWord::keyOf('Warm')) => ['de_DE' => 'Warm (de)'],
            ExtensionWord::field(ExtensionWord::keyOf('Tickets')) => ['de_DE' => 'Tickets (de)'],
        ]);
        $this->locale->locale = 'de_DE';
        $class = $this->scripts->load(self::SCRIPT . "\n", 'greeter')::class;
        $loaded = new LoadedPackage($this->package, $class, PackageManifest::ofClass($class), $this->translations);

        $settings = $this->translations->settings('greeter', ...$loaded->manifest->settings());
        self::assertSame(['Gruß', 'What it says', ['warm' => 'Warm (de)', 'cold' => 'Cold']], [$settings[0]->label, $settings[0]->help, $settings[1]->choices]);
        self::assertSame(['Tickets (de)', 'Greeter'], [$loaded->word('Tickets'), $loaded->word('Greeter')]);
    }

    public function testTheWordsOfAnEnabledExtensionAreThoseOfTheFilesItRuns(): void
    {
        $at = new \DateTimeImmutable('2026-11-10');
        $this->package->enable([], ['templates'], $at);
        $this->package->write('main.ocs', str_replace('__("Free shipping")', '__("Edited since")', self::SCRIPT) . "\n", $at);
        $this->package->write('templates/note.html.twig', "<p>{{ __('A new note') }}</p>\n", $at);
        $words = new ExtensionWords($this->scripts, new TwigTemplateWords(self::twig($this->translations)));

        $texts = array_map(static fn(ExtensionWord $word): string => $word->text, $words->of($this->package));
        self::assertContains('Free shipping', $texts);
        self::assertContains('Thanks', $texts);
        self::assertNotContains('Edited since', $texts, 'an edit\'s words wait for it to be enabled');
        self::assertNotContains('A new note', $texts);

        $this->package->disable($at);
        $texts = array_map(static fn(ExtensionWord $word): string => $word->text, $words->of($this->package));
        self::assertContains('Edited since', $texts, 'switched off, it is the files as they are');
        self::assertContains('A new note', $texts);
    }

    public function testTranslationsAreSavedByWordAndLanguageWithTheirFormsAndListed(): void
    {
        $words = new ExtensionWords($this->scripts, new TwigTemplateWords(self::twig($this->translations)));
        $service = new PackageTranslations($this->repository, $words, new ContentTranslations($this->records, $this->records, new FakeContentLanguages([new Language('en', 'en_US', 'English'), new Language('de', 'de_DE', 'Deutsch'), new Language('pl', 'pl_PL', 'Polski')]), $this->locale), $this->translations);
        $item = ExtensionWord::keyOf('%d item', '%d items');
        $hello = ExtensionWord::keyOf('Hello %s');

        $shown = $service->save('greeter', [$hello => ['de_DE' => 'Hallo %s', 'pl_PL' => 'Cześć %s'], $item => ['pl_PL' => ['%d produkt', '%d produkty', '%d produktów']]]);
        self::assertSame([['de_DE', 'Deutsch', 2], ['pl_PL', 'Polski', 3]], array_map(static fn(array $l): array => [$l['locale'], $l['name'], $l['forms']], $shown['languages']));
        $byKey = array_column($shown['words'], 'translations', 'key');
        self::assertSame(['de_DE' => ['Hallo %s'], 'pl_PL' => ['Cześć %s']], $byKey[$hello]);
        self::assertSame(['de_DE' => ['', ''], 'pl_PL' => ['%d produkt', '%d produkty', '%d produktów']], $byKey[$item], 'as many forms as each language has');

        // A language left out keeps its translation; an empty one removes it.
        $service->save('greeter', [$hello => ['pl_PL' => '']]);
        self::assertSame([$hello => ['de_DE' => ['Hallo %s']], $item => ['pl_PL' => ['%d produkt', '%d produkty', '%d produktów']]], $service->export($this->package));

        try {
            $service->save('greeter', ['0123456789abcdef' => ['de_DE' => 'x']]);
            self::fail('a word the extension does not have');
        } catch (PackageRuleViolation $e) {
            self::assertSame('orillacart_extension_word', $e->errorCode);
        }
        try {
            $service->save('greeter', [$item => ['de_DE' => ['a', 'b', 'c']]]);
            self::fail('more forms than German has');
        } catch (PackageRuleViolation $e) {
            self::assertSame('orillacart_extension_word_forms', $e->errorCode);
        }

        // An import takes what it can: words it does not have and forms beyond the language's are left.
        $service->import($this->package, ['0123456789abcdef' => ['de_DE' => 'x'], $item => ['de_DE' => ['%d Stück', '%d Stück', 'drei']]]);
        self::assertSame(['%d Stück', '%d Stück'], $service->export($this->package)[$item]['de_DE']);
        $service->forget($this->package);
        self::assertSame([], $service->export($this->package));
    }

    public function testAnExtensionsTemplatesReadItsWordsAndThePluginsAreLeftAlone(): void
    {
        $this->records->replace(ExtensionWordTypes::TYPE, 7, [
            ExtensionWord::field(ExtensionWord::keyOf('Thanks')) => ['de_DE' => 'Danke'],
            ExtensionWord::field(ExtensionWord::keyOf('%d day', '%d days')) => ['de_DE' => '%d Tag'],
            ExtensionWord::field(ExtensionWord::keyOf('%d day', '%d days'), 1) => ['de_DE' => '%d Tage'],
        ]);
        $this->locale->locale = 'de_DE';
        $twig = self::twig($this->translations, ['x/greeter/note.html.twig' => $this->package->file('templates/note.html.twig')?->content() ?? '', 'x/greeter/bad.html.twig' => '{{ __(name) }}'], ['plugin.html.twig' => "{{ __('Thanks') }}"]);

        self::assertSame("<p>Danke</p>\n<p>3 Tage</p>\n", str_replace('%d', '3', $twig->render('x/greeter/note.html.twig', ['days' => 3])));
        self::assertSame('Thanks', $twig->render('plugin.html.twig'), 'the plugin\'s own template reads the plugin\'s words');
        try {
            $twig->render('x/greeter/bad.html.twig', ['name' => 'x']);
            self::fail('words not written as a string');
        } catch (SyntaxError $e) {
            self::assertStringContainsString('"__" takes the words themselves', $e->getMessage());
        }

        $words = new TwigTemplateWords($twig);
        self::assertSame(['line' => 1, 'message' => '"__" takes the words themselves, written as a string, so they can be listed for translators: put the values in after, as __("%s left")|replace({"%s": count}).'], $words->problem('greeter', 'templates/bad.html.twig', '{{ __(name) }}'));
        self::assertNull($words->problem('greeter', 'templates/note.html.twig', "{{ _x('Post', 'a verb') }}"));
    }

    /**
     * The storefront's Twig with an extension's templates, as TwigFactory makes it.
     *
     * @param array<string, string> $extensions by name, under x/greeter/
     * @param array<string, string> $plugin     the plugin's own
     */
    private static function twig(ExtensionTranslations $translations, array $extensions = [], array $plugin = []): Environment
    {
        $templates = new class ($extensions) implements PackageTemplates {
            /** @param array<string, string> $sources */
            public function __construct(private readonly array $sources) {}

            public function source(string $name): ?string
            {
                return $this->sources[$name] ?? null;
            }

            public function owner(string $name): ?string
            {
                return isset($this->sources[$name]) ? 'greeter' : null;
            }
        };
        $loader = new PackageTemplateLoader($templates);
        $twig = new Environment(new ChainLoader([$loader, new ArrayLoader($plugin)]), ['strict_variables' => true, 'autoescape' => false]);
        $geography = new GeographyService(new InMemoryCountryRepository());
        $twig->addExtension(new ShopExtension(new SettingsService(new InMemorySettingsRepository(new StoreSettings(new Currency('EUR'), null, WeightUnit::Kilogram, DimensionUnit::Centimetre)), new RecordingDispatcher(), $geography), $geography));
        $twig->addExtension(new SandboxExtension(StorefrontSandbox::policy(), false, $loader));
        $twig->addExtension(new ExtensionWordsExtension($translations));

        return $twig;
    }

    /** The plugin's own translations: it knows "Free shipping" and the plural of items in German. */
    private static function shop(FakeLanguages $locale): Translations
    {
        return new class ($locale) implements Translations {
            public function __construct(private readonly FakeLanguages $locale) {}

            public function text(Text|string $text): string
            {
                return (string) $text;
            }

            public function message(string $template, array $parameters = [], ?int $count = null): string
            {
                return $template;
            }

            public function word(string $text, string $context = ''): string
            {
                return $text === 'Free shipping' && $this->locale->locale === 'de_DE' ? 'Kostenloser Versand' : $text;
            }

            public function plural(string $single, string $plural, int $count, string $context = ''): string
            {
                return $single === '%d item' && $this->locale->locale === 'de_DE' ? '%d Stück' : ($count === 1 ? $single : $plural);
            }
        };
    }
}
