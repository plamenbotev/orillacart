<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

use OrillaCart\Customers\Application\Dto\CustomerRecord;

/**
 * Where customer accounts live: WordPress users with the shop's customer role, and their address books.
 */
interface Accounts
{
    public function find(int $id): ?CustomerRecord;

    /**
     * Whether the user is one of this shop's customers, for everything that reads or changes a customer by their
     * number: a user of this site (on a network) every one of whose roles is a customers' role, as the shop chose
     * them, and who cannot manage the site or the shop. Other users' details are not the shop's to show.
     */
    public function isShopCustomer(int $id): bool;

    /**
     * The site's roles the shop can choose as customers' roles, by name with the label WordPress shows: those
     * that can manage neither the site nor the shop.
     *
     * @return array<string, string>
     */
    public function availableRoles(): array;

    /** Whether the email has an account at this shop: on a network, a user of this site. */
    public function isRegistered(string $email): bool;

    /** Whether the site is one of a network of sites, whose users are the network's. */
    public function onNetwork(): bool;

    /**
     * The user of the email on the network who is not one of this site's: a customer of another shop of it.
     * Always null on a single site.
     */
    public function elsewhere(string $email): ?int;

    /**
     * Makes a user of another shop of the network a customer of this one, with the customer role here, after they
     * confirmed from the email. Their password and details stay as they are.
     */
    public function join(int $id): void;

    /** The account of the email, if any: a WordPress user may be another role's. */
    public function idOf(string $email): ?int;

    /**
     * @return int the new account's id
     */
    public function register(string $email, #[\SensitiveParameter] string $password, string $firstName, string $lastName): int;

    /** Logs the browser in as the account, as WordPress's login does. */
    public function logIn(int $id): void;

    public function saveAddresses(int $id, AddressBook $addresses): void;

    /** Whether checkout starts with the box ticked to remind them of a cart they leave. */
    public function saveCartReminders(int $id, bool $on): void;

    /**
     * The products the customer saved for later, newest first.
     *
     * @return list<int>
     */
    public function wishlist(int $id): array;

    /**
     * @param list<int> $productIds newest first
     */
    public function saveWishlist(int $id, array $productIds): void;

    /**
     * Customers newest first, and how many match in all. The search matches email and names.
     *
     * @return array{list<CustomerRecord>, int}
     */
    public function search(?string $text, int $offset, int $limit): array;

    /** Marks the account as made to try the shop out (design 62): never emailed, removed with the test data. */
    public function markTest(int $id): void;

    /** Whether the account of that email, if any, was made to try the shop out. */
    public function isTestEmail(string $email): bool;

    /**
     * The accounts made to try the shop out.
     *
     * @return list<int>
     */
    public function testIds(): array;

    /**
     * Removes the account from the site; on a network, from this site only. Posts the user wrote go to the user
     * removing it.
     */
    public function remove(int $id): void;
}
