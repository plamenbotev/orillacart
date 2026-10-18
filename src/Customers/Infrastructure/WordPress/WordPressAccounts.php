<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Infrastructure\WordPress;

use OrillaCart\Customers\Application\Accounts;
use OrillaCart\Customers\Application\Dto\CustomerRecord;
use OrillaCart\Ordering\Application\Dto\AddressData;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Lifecycle\LifecycleListener;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Customers are WordPress users. Those who made their account at checkout have the shop's customer role;
 * anyone logged in has an account with saved addresses, kept in user meta.
 */
final readonly class WordPressAccounts implements Accounts, LifecycleListener
{
    public const string ROLE = 'orillacart_customer';
    public const string BILLING = 'orillacart_billing';
    public const string SHIPPING = 'orillacart_shipping';

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

    public function register(string $email, string $password, string $firstName, string $lastName): int
    {
        $local = strstr($email, '@', true);
        $base = sanitize_user($local === false ? 'customer' : $local, true);
        $login = $base === '' ? 'customer' : $base;
        for ($suffix = 2; username_exists($login) !== false; ++$suffix) {
            $login = $base . $suffix;
        }
        $this->check($login, $email, $firstName, $lastName);
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

    /**
     * Lets add-ons refuse a sign-up, as anti-spam ones do. WordPress's own registration_errors is left alone: it
     * belongs to the wp-login.php form, and captcha plugins on it expect that form's fields, so they would refuse
     * every shop sign-up.
     */
    private function check(string $login, string $email, string $firstName, string $lastName): void
    {
        $errors = apply_filters('orillacart_registration_errors', new \WP_Error(), $email, $firstName, $lastName, $login);
        if ($errors instanceof \WP_Error && $errors->has_errors()) {
            throw new RuleViolation(wp_strip_all_tags($errors->get_error_message()), 'orillacart_registration_refused');
        }
    }

    public function logIn(int $id): void
    {
        wp_set_current_user($id);
        wp_set_auth_cookie($id, true, is_ssl());
    }

    public function saveAddresses(int $id, ?AddressData $billing, ?AddressData $shippingAddress): void
    {
        foreach ([self::BILLING => $billing, self::SHIPPING => $shippingAddress] as $key => $address) {
            if ($address === null) {
                delete_user_meta($id, $key);
            } else {
                update_user_meta($id, $key, $this->normalizer->normalize($address, 'json'));
            }
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
        delete_metadata('user', 0, self::BILLING, '', true);
        delete_metadata('user', 0, self::SHIPPING, '', true);
    }

    private function record(\WP_User $user): CustomerRecord
    {
        return new CustomerRecord(
            $user->ID,
            $user->user_email,
            $user->first_name,
            $user->last_name,
            (string) mysql2date(\DATE_ATOM, $user->user_registered, false),
            $this->address($user->ID, self::BILLING),
            $this->address($user->ID, self::SHIPPING),
        );
    }

    private function address(int $id, string $key): ?AddressData
    {
        $saved = get_user_meta($id, $key, true);
        if (!\is_array($saved)) {
            return null;
        }
        try {
            $address = $this->denormalizer->denormalize($saved, AddressData::class, 'json');
        } catch (\Throwable) {
            return null;
        }

        return $address instanceof AddressData ? $address : null;
    }
}
