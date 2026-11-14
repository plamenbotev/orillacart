<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

use OrillaCart\Catalog\Application\Dto\ProductOffer;
use OrillaCart\Catalog\Application\ShopCatalog;
use OrillaCart\Customers\Domain\Event\PersonErased;
use OrillaCart\Newsletter\Application\Newsletter;
use OrillaCart\Shared\Privacy\PersonalDataErasers;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * What the shop holds of a person, for WordPress's privacy tools (Tools → Export and Erase Personal Data): their
 * orders, their account's addresses, wishlist and the shop's notes about them, an account they asked for, their
 * newsletter sign-up, and what each area keeps of them (PersonalDataSource), given out or erased a page at a time. Erasing anonymises orders, which the books keep (see Order::anonymise()), and forgets
 * the rest.
 */
final readonly class PersonalData
{
    public const int PER_PAGE = 20;

    public function __construct(
        private CustomerOrders $orders,
        private CustomerService $customers,
        private CustomerNotes $notes,
        private Newsletter $newsletter,
        private Accounts $accounts,
        private ShopCatalog $catalog,
        private ?EventDispatcherInterface $events = null,
        private ?PersonalDataSources $sources = null,
        private PersonalDataErasers $erasers = new PersonalDataErasers(),
    ) {}

    /**
     * @return array{items: list<array{group: string, label: string, id: string, data: list<array{name: string, value: string}>}>, done: bool}
     */
    public function export(string $email, int $page): array
    {
        $items = [];
        if ($page === 1) {
            $id = $this->customers->customerIdOf($email);
            $customer = $id === null ? null : $this->customers->account($id);
            foreach ($customer->addresses ?? [] as $saved) {
                $items[] = ['group' => 'orillacart-account', 'label' => 'Shop account', 'id' => 'orillacart-account-address-' . $saved->id, 'data' => [['name' => 'Saved address', 'value' => $saved->address->oneLine()]]];
            }
            $subscription = $this->newsletter->subscription($email);
            if ($subscription !== null) {
                $items[] = ['group' => 'orillacart-newsletter', 'label' => 'Newsletter', 'id' => 'orillacart-newsletter', 'data' => array_values(array_filter([
                    ['name' => 'Signed up', 'value' => $subscription->subscribedAt],
                    ['name' => 'Agreed to', 'value' => $subscription->consent],
                    $subscription->unsubscribedAt === null ? null : ['name' => 'Unsubscribed', 'value' => $subscription->unsubscribedAt],
                ]))];
            }
            $wishlist = $id === null ? [] : $this->accounts->wishlist($id);
            if ($wishlist !== []) {
                $names = array_map(static fn(ProductOffer $offer): string => $offer->name, $this->catalog->offers($wishlist, $id));
                $items[] = ['group' => 'orillacart-account', 'label' => 'Shop account', 'id' => 'orillacart-account-wishlist', 'data' => [['name' => 'Wishlist', 'value' => implode(', ', array_map(static fn(int $productId): string => $names[$productId] ?? "#{$productId}", $wishlist))]]];
            }
            foreach ($id === null ? [] : $this->notes->of($id) as $note) {
                $items[] = ['group' => 'orillacart-account', 'label' => 'Shop account', 'id' => 'orillacart-account-note-' . $note->id, 'data' => [['name' => 'The shop\'s note', 'value' => $note->text]]];
            }
            $pending = $this->customers->pendingAccount($email);
            if ($pending !== null) {
                $items[] = ['group' => 'orillacart-account', 'label' => 'Shop account', 'id' => 'orillacart-account-requested', 'data' => [['name' => 'Account asked for, not confirmed yet', 'value' => $pending]]];
            }
            array_push($items, ...($this->sources?->of($email, $id) ?? []));
        }
        $orders = $this->orders->exportPage($email, $this->customers->customerIdOf($email), $page, self::PER_PAGE);
        array_push($items, ...$orders);

        return ['items' => $items, 'done' => \count($orders) < self::PER_PAGE];
    }

    /**
     * @return array{removed: bool, retained: bool, messages: list<string>, done: bool}
     */
    public function erase(string $email, int $page): array
    {
        $customerId = $this->customers->customerIdOf($email);
        $removed = false;
        if ($page === 1) {
            $account = $this->customers->erase($email);
            $removed = $this->newsletter->erase($email) || $account;
        }
        if ($page === 1) {
            $this->erasers->erasedPerson($email);
            $this->events?->dispatch(new PersonErased($email));
        }
        $orders = $this->orders->erasePage($email, $customerId, self::PER_PAGE);

        return [
            'removed' => $removed || $orders > 0,
            'retained' => $orders > 0,
            'messages' => $orders === 0 ? [] : [\sprintf('%d orders were kept for the books, with the customer\'s details erased.', $orders)],
            'done' => $orders < self::PER_PAGE,
        ];
    }

}
