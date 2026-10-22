<?php

declare(strict_types=1);

namespace OrillaCart\Forms\Infrastructure\Events;

use OrillaCart\Forms\Application\FormUploads;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\Event\OrderAnonymised;
use OrillaCart\Ordering\Domain\Event\OrderDeleted;
use OrillaCart\Ordering\Domain\Event\OrderPlaced;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The files shoppers sent with their items belong to the order once it is placed, and go with it: deleted when the
 * order is deleted or its customer erased.
 */
final readonly class KeepOrderUploads
{
    public function __construct(
        private FormUploads $uploads,
        private OrderService $orders,
    ) {}

    #[AsEventListener]
    public function placed(OrderPlaced $event): void
    {
        $ids = [];
        foreach ($this->orders->order($event->orderId)->lines as $line) {
            array_push($ids, ...self::uploadIds($line));
        }
        if ($ids !== []) {
            $this->uploads->take($event->orderId, $ids);
        }
    }

    #[AsEventListener]
    public function anonymised(OrderAnonymised $event): void
    {
        $this->uploads->forgetOrder($event->orderId);
    }

    #[AsEventListener]
    public function deleted(OrderDeleted $event): void
    {
        $this->uploads->forgetOrder($event->orderId);
    }

    /**
     * @return list<int>
     */
    private static function uploadIds(OrderLineView $line): array
    {
        $ids = [];
        foreach ($line->personalisation as $answer) {
            if ($answer['upload_id'] !== null) {
                $ids[] = $answer['upload_id'];
            }
        }

        return $ids;
    }
}
