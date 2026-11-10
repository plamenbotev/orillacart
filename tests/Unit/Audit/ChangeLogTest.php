<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Audit;

use OrillaCart\Audit\Application\ChangeLog;
use OrillaCart\Audit\Application\Dto\ChangeLogQuery;
use OrillaCart\Audit\Domain\ChangeRecord;
use OrillaCart\Extension\Setting;
use OrillaCart\Shared\Extension\SettingValues;
use OrillaCart\Shared\Security\SecretKeys;
use OrillaCart\Shared\Users\UserNames;
use OrillaCart\Tests\Fixtures\Audit\InMemoryChangeRecords;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ChangeLogTest extends TestCase
{
    public function testSecretsAreBlankedOutAndTheAreaIsTheRoutesFirstPart(): void
    {
        $records = new InMemoryChangeRecords();
        $log = new ChangeLog($records, new class implements UserNames {
            public function names(array $ids): array
            {
                return [1 => 'Ada'];
            }
        }, new MockClock('2026-10-21 08:00:00 UTC'));
        // The gateway's settings were checked against what it declares while the request was handled.
        SettingValues::accept([new Setting('client_id', 'Client ID'), new Setting('client_secret', 'Secret', Setting::SECRET), new Setting('webhook_id', 'Webhook ID'), new Setting('api_key', 'Key')], [], [], 'PayPal');

        $log->record(1, 'PUT', '/payment-methods/{gateway}', '/payment-methods/paypal', [
            'name' => 'PayPal',
            'settings' => ['client_id' => 'abc', 'client_secret' => 's3cret', 'webhook_id' => '', 'api_key' => null],
            'password' => 'hunter2',
            'key' => 'k',
        ]);

        $view = $log->page(new ChangeLogQuery())->records[0];
        self::assertSame(['Ada', 'payment-methods', 'PUT /payment-methods/{gateway}'], [$view->user, $view->area, $view->route]);
        self::assertSame(['name' => 'PayPal', 'settings' => ['client_id' => 'abc', 'client_secret' => '••••', 'webhook_id' => '', 'api_key' => null], 'password' => '••••', 'key' => '••••'], $view->values, 'an empty secret says it was emptied');
    }

    public function testASettingDeclaredSecretIsBlankedOutWhateverItsName(): void
    {
        $records = new InMemoryChangeRecords();
        $log = new ChangeLog($records, new class implements UserNames {
            public function names(array $ids): array
            {
                return [];
            }
        }, new MockClock('2026-10-21 08:00:00 UTC'));
        // The extension's settings were checked against what it declares while the request was handled.
        SettingValues::accept([new Setting('merchant_pin', 'PIN', Setting::SECRET), new Setting('signing_salt', 'Salt', Setting::SECRET), new Setting('shop_id', 'Shop', Setting::TEXT)], [], [], 'ERP');

        $log->record(1, 'PUT', '/extensions/{id}/settings', '/extensions/erp/settings', ['settings' => ['merchant_pin' => '1234', 'signing_salt' => 'NaCl', 'shop_id' => '7']]);

        self::assertSame(['settings' => ['merchant_pin' => '••••', 'signing_salt' => '••••', 'shop_id' => '7']], $log->page(new ChangeLogQuery())->records[0]->values);
    }

    public function testASettingTheRequestDidNotDeclareIsBlankedOut(): void
    {
        SecretKeys::reset();
        $records = new InMemoryChangeRecords();
        $log = new ChangeLog($records, new class implements UserNames {
            public function names(array $ids): array
            {
                return [];
            }
        }, new MockClock('2026-10-21 08:00:00 UTC'));

        $log->record(1, 'PUT', '/extensions/{id}/settings', '/extensions/erp/settings', ['note' => 'kept', 'settings' => ['access_code' => '1234', 'empty' => '', 'off' => false]]);

        self::assertSame(['note' => 'kept', 'settings' => ['access_code' => '••••', 'empty' => '', 'off' => false]], $log->page(new ChangeLogQuery())->records[0]->values, 'no declaration read: a value could be a secret, whatever its name');
    }

    public function testWhatIsTooLongIsNotKeptButTheChangeIs(): void
    {
        $records = new InMemoryChangeRecords();
        new ChangeLog($records, new class implements UserNames {
            public function names(array $ids): array
            {
                return [];
            }
        }, new MockClock())->record(null, 'POST', '/products/import', '/products/import', ['csv' => str_repeat('x', 10000)]);

        self::assertSame(['_' => 'Too long to keep.'], $records->records[0]->values());
        self::assertSame('', $records->records[0]->userName());
    }

    public function testAPrivacyRequestErasesWhatWasSentAboutThePersonAndKeepsTheChange(): void
    {
        $records = new InMemoryChangeRecords();
        $log = new ChangeLog($records, new class implements UserNames {
            public function names(array $ids): array
            {
                return [];
            }
        }, new MockClock());
        $log->record(1, 'PUT', '/orders/{id}/contact', '/orders/12/contact', ['billing' => ['first_name' => 'Ada']]);
        $log->record(1, 'PUT', '/orders/{id}/contact', '/orders/123/contact', ['billing' => ['first_name' => 'Grace']]);
        $log->record(1, 'POST', '/gift-cards', '/gift-cards', ['recipient_email' => 'Ada@Example.com']);
        $log->record(1, 'PUT', '/settings', '/settings', ['shop_name' => 'Lamps']);

        self::assertSame(2, $log->forget(['/orders/12'], 'ada@example.com'));

        self::assertSame([
            ['_' => 'Erased, as a privacy request asked.'],
            ['billing' => ['first_name' => 'Grace']],
            ['_' => 'Erased, as a privacy request asked.'],
            ['shop_name' => 'Lamps'],
        ], array_map(static fn(ChangeRecord $record): array => $record->values(), $records->records), 'not another order whose number starts the same');
        self::assertSame('PUT /orders/{id}/contact', $records->records[0]->route(), 'who changed what, and when, stays');
    }

    public function testAnEmailIsMatchedWholeNotWithinAnother(): void
    {
        $records = new InMemoryChangeRecords();
        $log = new ChangeLog($records, new class implements UserNames {
            public function names(array $ids): array
            {
                return [];
            }
        }, new MockClock());
        $log->record(1, 'POST', '/gift-cards', '/gift-cards', ['recipient_email' => 'john@gmail.com']);
        $log->record(1, 'POST', '/gift-cards', '/gift-cards', ['recipient_email' => 'N@gmail.com']);

        self::assertSame(1, $log->forget([], 'n@gmail.com'));
        self::assertSame(['recipient_email' => 'john@gmail.com'], $records->records[0]->values());
    }

    public function testAnEmailWithinALongerValueIsFoundButNotAsPartOfAnotherAddress(): void
    {
        $records = new InMemoryChangeRecords();
        $log = new ChangeLog($records, new class implements UserNames {
            public function names(array $ids): array
            {
                return [];
            }
        }, new MockClock());
        $log->record(1, 'POST', '/orders/{id}/notes', '/orders/7/notes', ['text' => 'Customer asked to write to Ada@Example.com instead.']);
        $log->record(1, 'POST', '/orders/{id}/notes', '/orders/8/notes', ['text' => 'Sent to <ada@example.com>, twice']);
        $log->record(1, 'POST', '/orders/{id}/notes', '/orders/9/notes', ['text' => 'Sent to lada@example.com and ada@example.community']);
        $log->record(1, 'POST', '/orders/{id}/notes', '/orders/10/notes', ['text' => 'Sent to ada@example.com.au']);

        self::assertSame(2, $log->forget([], 'ada@example.com'));
        self::assertSame([
            ['_' => 'Erased, as a privacy request asked.'],
            ['_' => 'Erased, as a privacy request asked.'],
            ['text' => 'Sent to lada@example.com and ada@example.community'],
            ['text' => 'Sent to ada@example.com.au'],
        ], array_map(static fn(ChangeRecord $record): array => $record->values(), $records->records));
    }
}
