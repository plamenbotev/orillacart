<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Shared\Extension;

use OrillaCart\Extension\Setting;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Extension\SettingValues;
use PHPUnit\Framework\TestCase;

final class SettingValuesTest extends TestCase
{
    /** @var list<Setting> */
    private array $fields;

    protected function setUp(): void
    {
        $this->fields = [
            new Setting('api_key', 'API key', Setting::SECRET, required: true),
            new Setting('mode', 'Mode', Setting::CHOICE, choices: ['test' => 'Test', 'live' => 'Live']),
            new Setting('note', 'Note', Setting::TEXT),
        ];
    }

    public function testASecretSentEmptyStaysAndSentFalseIsCleared(): void
    {
        $saved = ['api_key' => 'k3y', 'mode' => 'test', 'note' => ''];

        self::assertSame('k3y', SettingValues::accept($this->fields, $saved, ['api_key' => ''], 'Test Pay')['api_key']);
        self::assertSame('n3w', SettingValues::accept($this->fields, $saved, ['api_key' => 'n3w'], 'Test Pay')['api_key']);
        self::assertSame('', SettingValues::accept($this->fields, $saved, ['api_key' => false], 'Test Pay')['api_key']);
    }

    public function testTextHasALimitAndAChoiceSaysItsKeys(): void
    {
        foreach ([[['note' => str_repeat('a', SettingValues::MAX_LENGTH + 1)], '"Note" has up to 2000 characters.'], [['mode' => 'weekly'], '"Mode" is one of: test, live.']] as [$submitted, $message]) {
            try {
                SettingValues::accept($this->fields, [], $submitted, 'Test Pay');
                self::fail($message);
            } catch (RuleViolation $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }
}
