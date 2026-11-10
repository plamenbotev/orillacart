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

        self::assertSame('k3y', SettingValues::accept($this->fields, $saved, ['api_key' => '', 'mode' => 'test'], 'Test Pay')['api_key']);
        self::assertSame('n3w', SettingValues::accept($this->fields, $saved, ['api_key' => 'n3w', 'mode' => 'test'], 'Test Pay')['api_key']);
        self::assertSame('', SettingValues::accept($this->fields, $saved, ['api_key' => false, 'mode' => 'test'], 'Test Pay')['api_key']);
    }

    public function testASavedSecretIsForgottenWhenASettingItGoesWithChanges(): void
    {
        $fields = [
            new Setting('endpoint', 'Address', Setting::TEXT),
            new Setting('account_user', 'User', Setting::TEXT),
            new Setting('note', 'Note', Setting::TEXT),
            new Setting('hmac_key', 'Signing key', Setting::SECRET, goesWith: ['endpoint', 'account_user']),
        ];
        $saved = ['endpoint' => 'https://erp.example.com/api', 'account_user' => 'shop', 'note' => 'a', 'hmac_key' => 'k3y'];

        self::assertSame('k3y', SettingValues::accept($fields, $saved, ['endpoint' => 'HTTPS://erp.example.com/api/', 'account_user' => 'shop', 'note' => 'b'], 'ERP')['hmac_key'], 'the same address, another note');
        self::assertSame('', SettingValues::accept($fields, $saved, ['endpoint' => 'https://attacker.example.net/api', 'account_user' => 'shop', 'note' => 'a'], 'ERP')['hmac_key']);
        self::assertSame('', SettingValues::accept($fields, $saved, ['endpoint' => 'https://erp.example.com/api', 'account_user' => 'other', 'note' => 'a'], 'ERP')['hmac_key']);
        self::assertSame('n3w', SettingValues::accept($fields, $saved, ['endpoint' => 'https://attacker.example.net/api', 'account_user' => 'shop', 'hmac_key' => 'n3w'], 'ERP')['hmac_key'], 'typed again');

        $byValue = [new Setting('where', 'Where', Setting::TEXT), new Setting('pin', 'PIN', Setting::SECRET)];
        self::assertSame(['where', 'pin'], array_keys(SettingValues::accept($byValue, [], [], 'ERP')), 'in their order');
    }

    public function testASecretThatSaysNothingGoesWithEveryOtherSetting(): void
    {
        // Named as nothing an address or a user would be: guessing from names would keep the PIN for a new place.
        $fields = [new Setting('target', 'Where to', Setting::TEXT), new Setting('sandbox', 'Test mode', Setting::BOOLEAN), new Setting('pin', 'PIN', Setting::SECRET)];
        $saved = ['target' => 'erp-1', 'sandbox' => false, 'pin' => '1234'];

        self::assertSame('1234', SettingValues::accept($fields, $saved, ['target' => 'erp-1', 'sandbox' => false], 'ERP')['pin'], 'nothing changed');
        self::assertSame('', SettingValues::accept($fields, $saved, ['target' => 'erp-2', 'sandbox' => false], 'ERP')['pin']);
        self::assertSame('', SettingValues::accept($fields, $saved, ['target' => 'erp-1', 'sandbox' => true], 'ERP')['pin']);
    }

    public function testACheckboxNeverSavedIsNoChange(): void
    {
        // The update that brought "Test mode" was installed after the PIN was saved.
        $fields = [new Setting('target', 'Where to', Setting::TEXT), new Setting('sandbox', 'Test mode', Setting::BOOLEAN), new Setting('pin', 'PIN', Setting::SECRET)];
        $saved = ['target' => 'erp-1', 'pin' => '1234'];

        self::assertSame('1234', SettingValues::accept($fields, $saved, ['target' => 'erp-1', 'sandbox' => false], 'ERP')['pin'], 'left off, as it was shown');
        self::assertSame('1234', SettingValues::accept($fields, $saved, ['target' => 'erp-1'], 'ERP')['pin'], 'not sent at all');
        self::assertSame('', SettingValues::accept($fields, $saved, ['target' => 'erp-1', 'sandbox' => true], 'ERP')['pin'], 'turned on: a change');
    }

    public function testASecretThatGoesWithNothingStaysWhateverChanges(): void
    {
        $fields = [new Setting('sandbox', 'Test mode', Setting::BOOLEAN), new Setting('secret_key', 'Secret key', Setting::SECRET, goesWith: [])];

        self::assertSame('sk', SettingValues::accept($fields, ['sandbox' => false, 'secret_key' => 'sk'], ['sandbox' => true], 'Pay')['secret_key']);
    }

    public function testOnlyASecretSaysWhatItGoesWith(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Setting('host', 'Host', Setting::TEXT, goesWith: ['user']);
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

    public function testOneSettingChangesAloneAndTheOthersStayAsSaved(): void
    {
        $fields = [new Setting('api_key', 'API key', Setting::SECRET, goesWith: ['mode']), $this->fields[1], $this->fields[2], new Setting('active', 'Active', Setting::BOOLEAN), new Setting('points', 'Points per euro', Setting::NUMBER)];
        $saved = ['api_key' => 'k3y', 'mode' => 'live', 'note' => 'Hi', 'active' => true, 'points' => '2'];

        // A tool that saves one setting does not send the rest: they stay, the live offer with them.
        self::assertSame(['api_key' => 'k3y', 'mode' => 'live', 'note' => 'Hi', 'active' => true, 'points' => '3'], SettingValues::accept($fields, $saved, ['points' => '3'], 'Loyalty'));
        self::assertSame('', SettingValues::accept($fields, $saved, ['note' => ''], 'Loyalty')['note'], 'sent empty, it is cleared');
        self::assertFalse(SettingValues::accept($fields, $saved, ['active' => false], 'Loyalty')['active']);
        self::assertSame('', SettingValues::accept($fields, ['mode' => 'gone'], ['note' => 'x'], 'Loyalty')['mode'], 'a choice an update dropped is as never saved');
        self::assertSame(['api_key' => '', 'mode' => '', 'note' => 'x', 'active' => false, 'points' => ''], SettingValues::accept($fields, [], ['note' => 'x'], 'Loyalty'), 'never saved: empty, off');
    }
}
