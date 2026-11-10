<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Assistant;

use OrillaCart\Assistant\Application\AssistantKeys;
use OrillaCart\Assistant\Application\Dto\AssistantChoices;
use OrillaCart\Integrations\Application\ApiKeys;
use OrillaCart\Integrations\Application\Dto\CreateApiKey;
use OrillaCart\Integrations\Domain\IntegrationRuleViolation;
use OrillaCart\Shared\Http\Routing\Tool;
use OrillaCart\Shared\Users\UserNames;
use OrillaCart\Tests\Unit\Integrations\InMemoryApiKeys;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

require_once __DIR__ . '/../Integrations/ApiKeysTest.php';

final class AssistantKeysTest extends TestCase
{
    private MockClock $clock;
    private ApiKeys $apiKeys;
    private AssistantKeys $keys;
    private int $key;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-11 08:00:00 UTC');
        $this->apiKeys = new ApiKeys(new InMemoryApiKeys(), new class implements UserNames {
            public function names(array $ids): array
            {
                return [1 => 'Ada', 2 => 'Bo'];
            }
        }, $this->clock);
        $this->keys = new AssistantKeys($this->apiKeys, $this->clock);
        $this->key = $this->apiKeys->create(new CreateApiKey('Claude', 'read_write'), 1)->apiKey->id;
    }

    public function testAToolLetRunUnaskedStaysUntilItRunsOut(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->keys->allow($this->key, 1, 'write_setting', $now + 3600);
        $this->keys->allow($this->key, 1, 'change_page', $now + 900);

        self::assertSame(['change_page' => $now + 900, 'write_setting' => $now + 3600], $this->keys->allowances($this->key, 1, false), 'the soonest to run out first');
        self::assertEqualsCanonicalizing(['change_page', 'write_setting'], array_keys(AssistantKeys::allowed($this->apiKeys->assistant($this->key, 1, false), $now)));

        $this->clock->sleep(901);
        self::assertSame(['write_setting'], array_keys($this->keys->allowances($this->key, 1, false)));
        self::assertSame(['write_setting'], array_keys(AssistantKeys::allowed($this->apiKeys->assistant($this->key, 1, false), $now + 901)));
    }

    public function testSavingTheChoicesKeepsWhatIsLetRunUnaskedAndAskingAgainEndsIt(): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->keys->allow($this->key, 1, 'write_setting', $now + 3600);
        $this->keys->allow($this->key, 1, 'change_page', $now + 3600);

        $this->keys->choose($this->key, new AssistantChoices(ask: [Tool::MONEY], allowMinutes: 15), 1, false);
        self::assertSame([Tool::MONEY], $this->keys->choices($this->key, 1, false)->ask);
        self::assertSame(15, $this->keys->choices($this->key, 1, false)->allowMinutes);
        self::assertCount(2, $this->keys->allowances($this->key, 1, false));

        self::assertSame(['write_setting'], array_keys($this->keys->ask($this->key, 'change_page', 1, false)));
        self::assertSame([], $this->keys->ask($this->key, null, 1, false), 'all of them');
        self::assertSame([Tool::MONEY], $this->keys->choices($this->key, 1, false)->ask, 'the choices stay');
    }

    public function testOnlyTheKeysOwnUserLetsItRunUnasked(): void
    {
        $this->expectException(IntegrationRuleViolation::class);
        $this->keys->allow($this->key, 2, 'write_setting', $this->clock->now()->getTimestamp() + 60);
    }

    public function testWhatIsStoredOddlyIsNotLetRun(): void
    {
        $now = 1000;
        self::assertSame(['ok_tool' => 2000], AssistantKeys::allowed([AssistantKeys::ALLOWED => ['ok_tool' => 2000, 'Bad-Name' => 2000, 'late' => 999, 'text' => '3000', 0 => 3000]], $now));
        self::assertSame([], AssistantKeys::allowed(null, $now));
        self::assertSame([], AssistantKeys::allowed([AssistantKeys::ALLOWED => 'all'], $now));
    }
}
