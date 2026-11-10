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

    public function testASavedSecretIsForgottenWhenTheAddressOrUserItGoesToChanges(): void
    {
        $fields = [
            new Setting('endpoint', 'Address', Setting::TEXT),
            new Setting('account_user', 'User', Setting::TEXT),
            new Setting('note', 'Note', Setting::TEXT),
            new Setting('hmac_key', 'Signing key', Setting::SECRET),
        ];
        $saved = ['endpoint' => 'https://erp.example.com/api', 'account_user' => 'shop', 'note' => 'a', 'hmac_key' => 'k3y'];

        self::assertSame('k3y', SettingValues::accept($fields, $saved, ['endpoint' => 'HTTPS://erp.example.com/api/', 'account_user' => 'shop', 'note' => 'b'], 'ERP')['hmac_key'], 'the same address, another note');
        self::assertSame('', SettingValues::accept($fields, $saved, ['endpoint' => 'https://attacker.example.net/api', 'account_user' => 'shop', 'note' => 'a'], 'ERP')['hmac_key']);
        self::assertSame('', SettingValues::accept($fields, $saved, ['endpoint' => 'https://erp.example.com/api', 'account_user' => 'other', 'note' => 'a'], 'ERP')['hmac_key']);
        self::assertSame('n3w', SettingValues::accept($fields, $saved, ['endpoint' => 'https://attacker.example.net/api', 'account_user' => 'shop', 'hmac_key' => 'n3w'], 'ERP')['hmac_key'], 'typed again');

        $byValue = [new Setting('where', 'Where', Setting::TEXT), new Setting('pin', 'PIN', Setting::SECRET)];
        self::assertSame('', SettingValues::accept($byValue, ['where' => 'erp.example.com', 'pin' => '1234'], ['where' => 'erp.example.net'], 'ERP')['pin'], 'a value that is an address');
        self::assertSame(['where', 'pin'], array_keys(SettingValues::accept($byValue, [], [], 'ERP')), 'in their order');
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
