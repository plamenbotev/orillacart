<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Packages;

use OrillaCart\Packages\Application\ObjectDefinitions;
use OrillaCart\Packages\Application\ObjectStore;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Packages\Application\PackageTests;
use OrillaCart\Packages\Domain\Package;
use OrillaCart\Packages\Domain\PackageRepository;
use OrillaCart\Scripting\Application\Compiler;
use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\Host\Builtins;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Scripting\Application\ScriptTests;
use OrillaCart\Scripting\Domain\Ops;
use OrillaCart\Shared\Http\HttpException;
use OrillaCart\Shared\Security\ReadOnlyRequest;
use OrillaCart\Tests\Fixtures\Scripting\InMemoryScriptStore;
use PHPUnit\Framework\TestCase;

/**
 * An extension's own tests hold the shop's functions to what a manager granted, answer from the stand-ins for
 * what the files only declare, read no stored setting, and take no files from a caller that may only read.
 */
final class PackageTestsTest extends TestCase
{
    private const string SCRIPT = <<<'OCS'
        extension "peek" { name: "Peek", needs: { http: ["api.peek.test"], orders: [read], customers: [read], email: [send] } }

        test "reads an order" fn() {
          assert shop.orders.find(5) == "order 5"
        }

        test "reads a customer" fn() {
          assert shop.customers.find(5) == "customer 5"
        }

        test "posts to its provider" {
          http: { "POST https://api.peek.test/x": { status: 200 } }
        } fn() {
          assert http.post("https://api.peek.test/x", { json: {} }).status == 200
        }

        test "mails" fn() {
          assert shop.email.send("a@example.test", "Hi", "Hello")
        }

        test "sees no stored secret" fn() {
          assert settings.get("api_secret") == null, "the secret is " ~ settings.get("api_secret")
        }

        test "sees its own settings" { settings: { api_secret: "fake" } } fn() {
          assert settings.get("api_secret") == "fake"
        }
        OCS;

    private Package $package;
    private bool $readOnly = false;
    /** How many tests run before the shop's database refuses a write; null: never. */
    private ?int $breaksAfter = null;
    /** @var list<string> */
    private array $sent = [];

    protected function setUp(): void
    {
        $at = new \DateTimeImmutable('2026-11-10');
        $this->package = new Package('peek', 'Peek', $at);
        $this->package->write('main.ocs', self::SCRIPT . "\n", $at);
        $this->package->changeSettings(['api_secret' => 'stored-secret'], $at);
    }

    public function testADraftsTestsReachNoShopFunctionButTheStandInsAnswerForWhatItDeclares(): void
    {
        $results = $this->results();

        self::assertFalse($results['reads an order']['ok'], 'a draft was granted nothing');
        self::assertStringContainsString('not granted', (string) $results['reads an order']['message']);
        self::assertTrue($results['posts to its provider']['ok'], (string) $results['posts to its provider']['message']);
        self::assertTrue($results['mails']['ok'], (string) $results['mails']['message']);
        self::assertSame([], $this->sent, 'the mail was held');
    }

    public function testAnEnabledExtensionsTestsHaveWhatWasGrantedAndNoMore(): void
    {
        $this->package->enable(['orders:read'], ['orders:read'], new \DateTimeImmutable('2026-11-10'));

        $results = $this->results();

        self::assertTrue($results['reads an order']['ok'], (string) $results['reads an order']['message']);
        self::assertFalse($results['reads a customer']['ok'], 'the files declare customers, the manager did not grant them');
        self::assertStringContainsString('not granted', (string) $results['reads a customer']['message']);
        self::assertTrue($results['mails']['ok'], (string) $results['mails']['message']);
        self::assertSame([], $this->sent);
    }

    public function testATestReadsNoStoredSettingOnlyItsOwn(): void
    {
        $this->package->enable(['http', 'orders:read', 'customers:read', 'email:send'], ['http', 'orders:read', 'customers:read', 'email:send'], new \DateTimeImmutable('2026-11-10'));

        $results = $this->results();

        self::assertTrue($results['sees no stored secret']['ok'], (string) $results['sees no stored secret']['message']);
        self::assertTrue($results['sees its own settings']['ok'], (string) $results['sees its own settings']['message']);
    }

    public function testAfterATestTheDatabaseRefusedTheOthersAreNotRunAndSayWhy(): void
    {
        $this->breaksAfter = 2;

        $results = array_values($this->results());

        self::assertCount(6, $results);
        self::assertStringNotContainsString('Not run', (string) $results[1]['message'], 'the test that broke it says its own failure');
        foreach (\array_slice($results, 2) as $result) {
            self::assertFalse($result['ok']);
            self::assertSame('Not run: the shop\'s database refused a write in "reads a customer", and nothing more can be written until the tests run again.', $result['message']);
        }
    }

    public function testACallerThatMayOnlyReadCannotSendFiles(): void
    {
        $this->readOnly = true;

        try {
            $this->tests()->run('peek', ['main.ocs' => self::SCRIPT]);
            self::fail('files from a read-only caller ran');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status);
        }
        self::assertCount(6, $this->tests()->run('peek'), 'the saved files are tested');
    }

    /** @return array<string, array{ok: bool, message: ?string}> */
    private function results(): array
    {
        $byName = [];
        foreach ($this->tests()->run('peek') as $result) {
            $byName[$result['name']] = $result;
        }

        return $byName;
    }

    private function tests(): PackageTests
    {
        $sent = &$this->sent;
        $catalog = HostCatalog::of([
            ...new Builtins()->functions(),
            new HostFunction('shop.orders.find', static fn(Context $c, mixed $id): string => 'order ' . Ops::text($id), 1, 1, '', 'orders:read', HostFunction::QUERY),
            new HostFunction('shop.customers.find', static fn(Context $c, mixed $id): string => 'customer ' . Ops::text($id), 1, 1, '', 'customers:read', HostFunction::QUERY),
            new HostFunction('shop.email.send', static function (Context $c, mixed $to) use (&$sent): bool {
                $sent[] = Ops::text($to);

                return true;
            }, 3, 3, '', 'email:send', HostFunction::EMAIL, static fn(Context $c): bool => true),
            new HostFunction('http.post', static fn(Context $c): array => ['status' => 500, 'headers' => [], 'body' => 'real', 'json' => null], 1, 2, '', 'http', HostFunction::HTTP),
        ]);
        $scripts = new Scripts(new Compiler($catalog), $catalog, new InMemoryScriptStore());
        $package = $this->package;
        $repository = new class ($package) implements PackageRepository {
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
        $objects = self::createStub(ObjectStore::class);
        $ran = 0;
        $breaksAfter = $this->breaksAfter;
        $objects->method('isolated')->willReturnCallback(static function (callable $work) use (&$ran): mixed {
            ++$ran;

            return $work();
        });
        $objects->method('intact')->willReturnCallback(static function () use (&$ran, $breaksAfter): bool {
            return $breaksAfter === null || $ran < $breaksAfter;
        });
        $definitions = new ObjectDefinitions(new \ReflectionClass(PackageService::class)->newInstanceWithoutConstructor());
        $readOnly = $this->readOnly;

        return new PackageTests($repository, $scripts, new ScriptTests($scripts), $objects, $definitions, new readonly class ($readOnly) implements ReadOnlyRequest {
            public function __construct(private bool $readOnly) {}

            public function readOnly(): bool
            {
                return $this->readOnly;
            }
        });
    }
}
