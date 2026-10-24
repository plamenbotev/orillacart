<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Infrastructure\WordPress;

use OrillaCart\Customers\Application\Accounts;
use OrillaCart\Customers\Application\AddressBook;
use OrillaCart\Customers\Application\Dto\CustomerRecord;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use OrillaCart\Shared\Lifecycle\RemovesShopData;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Customers are WordPress users. Those who made their account at checkout have the shop's customer role;
 * anyone logged in has an account with an address book, kept in user meta.
 */
final readonly class WordPressAccounts implements Accounts, LifecycleListener, RemovesShopData
{
    public const string ROLE = 'orillacart_customer';
    public const string ADDRESSES = 'orillacart_addresses';
    public const string NO_CART_REMINDERS = 'orillacart_no_cart_reminders';
    public const string WISHLIST = 'orillacart_wishlist';

    public function __construct(
        private NormalizerInterface $normalizer,
        private DenormalizerInterface $denormalizer,
    ) {}

    public function find(int $id): ?CustomerRecord
    {
        $user = get_userdata($id);

        return $user instanceof \WP_User ? $this->record($user) : null;
    }

    public function isRegistered(string $email): bool
    {
        return email_exists($email) !== false;
    }

    public function idOf(string $email): ?int
    {
        $id = email_exists($email);

        return \is_int($id) ? $id : null;
    }

    public function register(string $email, string $password, string $firstName, string $lastName): int
    {
        // Not the email's first part: a username is public (author pages, comments) and would give the address away.
        do {
            $login = 'customer-' . bin2hex(random_bytes(5));
        } while (username_exists($login) !== false);
        $id = wp_insert_user([
            'user_login' => $login,
            'user_email' => $email,
            'user_pass' => $password,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => trim("{$firstName} {$lastName}"),
            'role' => self::ROLE,
        ]);
        if ($id instanceof \WP_Error) {
            throw new RuleViolation($id->get_error_message(), 'orillacart_registration_failed');
        }

        return $id;
    }

    public function logIn(int $id): void
    {
        wp_set_current_user($id);
        wp_set_auth_cookie($id, true, is_ssl());
    }

    public function saveAddresses(int $id, AddressBook $addresses): void
    {
        if ($addresses->addresses === []) {
            delete_user_meta($id, self::ADDRESSES);

            return;
        }
        update_user_meta($id, self::ADDRESSES, [
            'addresses' => array_map(fn(AddressData $address): mixed => $this->normalizer->normalize($address, 'json'), $addresses->addresses),
            'billing' => $addresses->billingId,
            'shipping' => $addresses->shippingId,
        ]);
    }

    public function saveCartReminders(int $id, bool $on): void
    {
        if ($on) {
            delete_user_meta($id, self::NO_CART_REMINDERS);
        } else {
            update_user_meta($id, self::NO_CART_REMINDERS, '1');
        }
    }

    public function wishlist(int $id): array
    {
        $stored = get_user_meta($id, self::WISHLIST, true);

        return \is_array($stored) ? array_values(array_filter(array_map(static fn(mixed $productId): int => is_numeric($productId) ? (int) $productId : 0, $stored), static fn(int $productId): bool => $productId > 0)) : [];
    }

    public function saveWishlist(int $id, array $productIds): void
    {
        if ($productIds === []) {
            delete_user_meta($id, self::WISHLIST);
        } else {
            update_user_meta($id, self::WISHLIST, $productIds);
        }
    }

    public function search(?string $text, int $offset, int $limit): array
    {
        $query = new \WP_User_Query(array_filter([
            'role__in' => [self::ROLE],
            'orderby' => 'registered',
            'order' => 'DESC',
            'number' => $limit,
            'offset' => $offset,
            'count_total' => true,
            'search' => $text === null ? null : '*' . $text . '*',
            'search_columns' => $text === null ? null : ['user_email', 'user_login', 'display_name'],
        ], static fn(mixed $value): bool => $value !== null));

        $users = array_values(array_filter($query->get_results(), static fn(mixed $user): bool => $user instanceof \WP_User));

        return [array_map($this->record(...), $users), $query->get_total()];
    }

    public function activate(): void
    {
        if (get_role(self::ROLE) === null) {
            add_role(self::ROLE, 'Shop customer', ['read' => true]);
        }
    }

    public function upgrade(?string $fromVersion): void
    {
        $this->activate();
    }

    public function deactivate(): void {}

    public function uninstall(): void
    {
        // Customers keep their WordPress accounts, as subscribers; the shop's data about them goes.
        foreach (get_users(['role' => self::ROLE]) as $user) {
            if ($user instanceof \WP_User) {
                $user->set_role('subscriber');
            }
        }
        remove_role(self::ROLE);
        delete_metadata('user', 0, self::ADDRESSES, '', true);
        delete_metadata('user', 0, self::NO_CART_REMINDERS, '', true);
        delete_metadata('user', 0, self::WISHLIST, '', true);
    }

    private function record(\WP_User $user): CustomerRecord
    {
        return new CustomerRecord(
            $user->ID,
            $user->user_email,
            $user->first_name,
            $user->last_name,
            (string) mysql2date(\DATE_ATOM, $user->user_registered, false),
            $this->addressBook($user->ID),
            get_user_meta($user->ID, self::NO_CART_REMINDERS, true) !== '1',
        );
    }

    /** The saved address book; an address that no longer reads is left out. */
    private function addressBook(int $id): AddressBook
    {
        $saved = get_user_meta($id, self::ADDRESSES, true);
        if (!\is_array($saved) || !\is_array($saved['addresses'] ?? null)) {
            return new AddressBook();
        }
        $addresses = [];
        foreach ($saved['addresses'] as $addressId => $address) {
            try {
                $address = \is_array($address) ? $this->denormalizer->denormalize($address, AddressData::class, 'json') : null;
            } catch (\Throwable) {
                continue;
            }
            if ($address instanceof AddressData) {
                $addresses[(string) $addressId] = $address;
            }
        }
        $chosen = static fn(mixed $addressId): ?string => \is_string($addressId) && isset($addresses[$addressId]) ? $addressId : null;

        return new AddressBook($addresses, $chosen($saved['billing'] ?? null) ?? array_key_first($addresses), $chosen($saved['shipping'] ?? null));
    }
}
