<?php

declare(strict_types=1);

namespace OrillaCart\Tests\Unit\Packages;

use OrillaCart\Customers\Domain\CustomerNotFound;
use OrillaCart\Packages\Application\ExtensionFields;
use OrillaCart\Packages\Application\ObjectStore;
use OrillaCart\Packages\Application\PackageService;
use OrillaCart\Tests\Fixtures\Customers\InMemoryAccounts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An extension's fields are saved on the shop's own customers only: a member of staff, or a user who is not one of
 * the shop's, is not found and nothing is written.
 */
final class CustomerRecordFieldsTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function notCustomers(): iterable
    {
        yield 'a manager' => [true];
        yield 'no such user' => [false];
    }

    #[DataProvider('notCustomers')]
    public function testFieldsAreNotSavedOnWhoIsNotACustomer(bool $staff): void
    {
        $accounts = new InMemoryAccounts();
        $id = $staff ? $accounts->add('manager@example.com') : 404;
        if ($staff) {
            $accounts->staff[] = $id;
        }
        $store = $this->createMock(ObjectStore::class);
        $store->expects(self::never())->method('setField');
        $packages = new \ReflectionClass(PackageService::class)->newInstanceWithoutConstructor();

        $this->expectException(CustomerNotFound::class);

        new ExtensionFields($packages, $store, $accounts)->save('customer', $id, 'loyalty', ['tier' => 'gold']);
    }
}
