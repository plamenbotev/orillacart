<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Scripting;

use OrillaCart\Scripting\Application\Compiler;
use OrillaCart\Scripting\Application\Context;
use OrillaCart\Scripting\Application\Dto\Grant;
use OrillaCart\Scripting\Application\Dto\HostFunction;
use OrillaCart\Scripting\Application\Host\Builtins;
use OrillaCart\Scripting\Application\Host\TimeFunctions;
use OrillaCart\Scripting\Application\HostCatalog;
use OrillaCart\Scripting\Application\Scripts;
use OrillaCart\Scripting\Application\ScriptTests;
use OrillaCart\Tests\Fixtures\Scripting\InMemoryScriptStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * A script's own tests: HTTP answered from each test's map and refused outside it, time standing still, the
 * test's settings over the extension's, jobs recorded, an assertion's message and line, every other failure told.
 */
final class ScriptTestsTest extends TestCase
{
    private const string SCRIPT = <<<'OCS'
        extension "courier" { name: "Courier", needs: { http: ["api.courier.test"] } }

        fn quote(weight) {
          let answer = http.post("https://api.courier.test/quote", { json: { weight: weight, key: settings.get("api_key") } })
          if answer.status != 200 { fail unavailable("Courier answered " ~ answer.status) }
          return money(answer.json.price)
        }
        fn due() { return time.today() }
        fn remind(who) { jobs.push("remind", { who: who }, "2026-12-01 09:00") }

        test "quotes a rate" {
          http: { "POST https://api.courier.test/quote": { status: 200, json: { price: "5.00" } } }
          settings: { api_key: "k-1" }
        } fn() {
          assert quote(2) == money("5.00"), "the quote is " ~ quote(2)
        }

        test "stands at a day" { now: "2026-11-07 10:00:00" } fn() {
          assert due() == "2026-11-07"
        }

        test "records jobs" fn() {
          remind("Ada")
          assert true
        }

        test "sees a wrong quote" {
          http: { "https://api.courier.test/*": { json: { price: "6.00" } } }
        } fn() {
          assert quote(1) == money("5.00"), "the quote is " ~ quote(1)
        }

        test "an unanswered request" fn() {
          quote(1)
        }

        test "a provider down" {
          http: { "POST https://api.courier.test/quote": { status: 503, body: "down" } }
        } fn() {
          quote(1)
        }
        OCS;

    public function testEachTestRunsWithItsFakesAndTellsWhatWentWrong(): void
    {
        $calledTheRealHost = false;
        $catalog = HostCatalog::of([
            ...new Builtins()->functions(),
            ...new TimeFunctions(new MockClock('2020-01-01 00:00:00'))->functions(),
            new HostFunction('http.post', static function (Context $c) use (&$calledTheRealHost): array {
                $calledTheRealHost = true;

                return ['status' => 500, 'headers' => [], 'body' => '', 'json' => null];
            }, 1, 2, '', 'http', HostFunction::HTTP),
            new HostFunction('jobs.push', static function (Context $c) use (&$calledTheRealHost): null {
                $calledTheRealHost = true;

                return null;
            }, 1, 3, ''),
        ]);
        $scripts = new Scripts(new Compiler($catalog), $catalog, new InMemoryScriptStore());
        $isolated = 0;
        $results = new ScriptTests($scripts)->run(self::SCRIPT . "\n", 'courier_tests', new Grant('courier', ['http'], ['api.courier.test'], ['api_key' => 'saved']), static function (callable $one) use (&$isolated) {
            ++$isolated;

            return $one();
        });

        self::assertFalse($calledTheRealHost, 'the fakes stand in for http and jobs');
        self::assertSame(6, $isolated, 'each test ran inside the isolation given');
        $byName = [];
        foreach ($results as $result) {
            $byName[$result->name] = $result;
        }

        self::assertTrue($byName['quotes a rate']->ok, (string) $byName['quotes a rate']->message);
        self::assertSame([['method' => 'POST', 'url' => 'https://api.courier.test/quote', 'status' => 200], ['method' => 'POST', 'url' => 'https://api.courier.test/quote', 'status' => 200]], $byName['quotes a rate']->http);
        self::assertTrue($byName['stands at a day']->ok, (string) $byName['stands at a day']->message);
        self::assertTrue($byName['records jobs']->ok);
        self::assertSame([['name' => 'remind', 'payload' => ['who' => 'Ada'], 'at' => '2026-12-01 09:00']], $byName['records jobs']->jobs);

        self::assertFalse($byName['sees a wrong quote']->ok);
        self::assertSame('the quote is 6.0000', $byName['sees a wrong quote']->message);
        self::assertSame(30, $byName['sees a wrong quote']->line, 'the assert\'s line in the source');

        self::assertFalse($byName['an unanswered request']->ok);
        self::assertStringContainsString('The test has no answer for POST https://api.courier.test/quote', (string) $byName['an unanswered request']->message);
        self::assertFalse($byName['a provider down']->ok);
        self::assertSame('Failed with unavailable: Courier answered 503', $byName['a provider down']->message);
        self::assertNull($byName['a provider down']->line);
    }

    public function testWhatReachesOutsideTheShopIsHeldInTestsAndListed(): void
    {
        $sent = [];
        $catalog = HostCatalog::of([
            ...new Builtins()->functions(),
            new HostFunction('shop.email.send', static function (Context $c, mixed $to) use (&$sent): bool {
                $sent[] = $to;

                return true;
            }, 3, 3, '', 'email:send', HostFunction::EMAIL, static fn(Context $c, mixed $to, mixed $subject, mixed $body): bool => true),
            new HostFunction('mail.raw', static function (Context $c, mixed $to) use (&$sent): bool {
                $sent[] = $to;

                return true;
            }, 1, 1, '', null, HostFunction::EMAIL),
        ]);
        $scripts = new Scripts(new Compiler($catalog), $catalog, new InMemoryScriptStore());
        $source = <<<'OCS'
            extension "mailer" { name: "Mailer", needs: { email: send } }

            test "sends" fn() {
              assert shop.email.send("ada@shop.example", "Hi", "<p>Hi</p>")
            }

            test "a mail function with no stand-in" fn() {
              mail.raw("ada@shop.example")
            }
            OCS;

        $results = new ScriptTests($scripts)->run($source . "\n", 'mailer_tests', new Grant('mailer', ['email:send']));

        self::assertSame([], $sent, 'no mail left the shop');
        self::assertTrue($results[0]->ok, (string) $results[0]->message);
        self::assertSame([['function' => 'shop.email.send', 'arguments' => ['ada@shop.example', 'Hi', '<p>Hi</p>']]], $results[0]->effects);
        self::assertFalse($results[1]->ok);
        self::assertStringContainsString('mail.raw does not run in tests', (string) $results[1]->message);
    }

    public function testASourceWithoutTestsHasNoResults(): void
    {
        $catalog = HostCatalog::of(new Builtins()->functions());
        $scripts = new Scripts(new Compiler($catalog), $catalog, new InMemoryScriptStore());

        self::assertSame([], new ScriptTests($scripts)->run("fn f() { return 1 }\n", 'no_tests', new Grant()));
    }
}
