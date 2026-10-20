<?php

declare(strict_types=1);

namespace OrillaCart\Customers\Application;

use OrillaCart\Customers\Application\Dto\AddCustomerNote;
use OrillaCart\Customers\Application\Dto\CustomerNoteView;
use OrillaCart\Customers\Domain\CustomerNote;
use OrillaCart\Customers\Domain\CustomerNoteRepository;
use OrillaCart\Customers\Domain\CustomerNotFound;
use OrillaCart\Customers\Domain\CustomerRuleViolation;
use OrillaCart\Shared\Users\UserNames;
use Psr\Clock\ClockInterface;

/**
 * What the shop notes about its customers, for itself, with who wrote each note.
 */
final readonly class CustomerNotes
{
    public function __construct(
        private CustomerNoteRepository $notes,
        private Accounts $accounts,
        private UserNames $users,
        private ClockInterface $clock,
    ) {}

    /**
     * @return list<CustomerNoteView> newest first
     */
    public function of(int $customerId): array
    {
        $notes = $this->notes->ofCustomer($customerId);
        $names = $this->users->names(array_values(array_filter(array_map(static fn(CustomerNote $note): ?int => $note->userId(), $notes), static fn(?int $id): bool => $id !== null)));

        return array_map(static fn(CustomerNote $note): CustomerNoteView => CustomerNoteView::fromNote($note, $names[$note->userId() ?? 0] ?? null), $notes);
    }

    /**
     * @throws CustomerNotFound
     * @throws CustomerRuleViolation for an empty note, or one too long
     */
    public function add(int $customerId, AddCustomerNote $command, ?int $userId): CustomerNoteView
    {
        $this->accounts->find($customerId) ?? throw new CustomerNotFound($customerId);
        $note = new CustomerNote($customerId, $command->text, $userId, $this->clock->now());
        $this->notes->save($note);

        return CustomerNoteView::fromNote($note, $userId === null ? null : $this->users->names([$userId])[$userId] ?? null);
    }

    /**
     * @throws CustomerRuleViolation for a note that is not about the customer
     */
    public function remove(int $customerId, int $noteId): void
    {
        $note = $this->notes->find($noteId);
        if ($note === null || $note->customerId() !== $customerId) {
            throw new CustomerRuleViolation('There is no such note about the customer.', 'orillacart_unknown_note');
        }
        $this->notes->remove($note);
    }
}
