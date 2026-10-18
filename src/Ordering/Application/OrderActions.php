<?php

declare(strict_types=1);

namespace OrillaCart\Ordering\Application;

use OrillaCart\Extension\Order\OrderAction;
use OrillaCart\Extension\Order\OrderSummary;
use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Ordering\Application\Dto\AddOrderNote;
use OrillaCart\Ordering\Application\Dto\OrderActionResult;
use OrillaCart\Ordering\Application\Dto\OrderActionView;
use OrillaCart\Ordering\Domain\OrderNote;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Ordering\Domain\OrderRuleViolation;
use OrillaCart\Shared\Domain\NotFound;
use OrillaCart\Shared\Extension\InstalledExtensions;
use Psr\Log\LoggerInterface;

/**
 * The buttons add-ons put on the order screen: which an order offers, and running one.
 */
final readonly class OrderActions
{
    public function __construct(
        private InstalledExtensions $extensions,
        private OrderService $orders,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return list<OrderActionView>
     *
     * @throws OrderNotFound
     */
    public function available(int $number): array
    {
        $summary = OrderSummaries::summary($this->orders->order($number));

        return array_values(array_map(
            static fn(OrderAction $action): OrderActionView => new OrderActionView($action->id(), $action->label()),
            array_filter($this->extensions->orderActions(), fn(OrderAction $action): bool => $this->offers($action, $summary)),
        ));
    }

    /**
     * Runs the action and records its answer in the order's history.
     *
     * @throws NotFound           for no such order, or an action it does not offer
     * @throws OrderRuleViolation when the add-on could not do it
     */
    public function run(int $number, string $actionId, ?int $userId): OrderActionResult
    {
        $summary = OrderSummaries::summary($this->orders->order($number));
        foreach ($this->extensions->orderActions() as $action) {
            if ($action->id() !== $actionId || !$this->offers($action, $summary)) {
                continue;
            }
            try {
                $message = trim($action->run($summary));
            } catch (ProviderUnavailable $e) {
                $this->logger->warning('The order action {action} failed for order #{order}: {message}', ['action' => $actionId, 'order' => $number, 'message' => $e->getMessage()]);

                throw new OrderRuleViolation($e->getMessage(), 'orillacart_order_action_failed', $e);
            }
            $note = mb_substr($action->label() . ($message === '' ? '' : ': ' . $message), 0, OrderNote::MAX_LENGTH);

            return new OrderActionResult($message, $this->orders->addNote($number, new AddOrderNote($note), $userId));
        }

        throw new NotFound('This order offers no such action.', 'orillacart_order_action_not_found');
    }

    /** Whether the order offers the action; an add-on that fails to say is logged and left out. */
    private function offers(OrderAction $action, OrderSummary $summary): bool
    {
        try {
            return $action->isAvailable($summary);
        } catch (\Throwable $e) {
            $this->logger->error('The order action {action} failed to say whether order #{order} offers it: {message}', ['action' => $action->id(), 'order' => $summary->number, 'message' => $e->getMessage(), 'exception' => $e]);

            return false;
        }
    }
}
