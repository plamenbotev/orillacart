<?php

declare(strict_types=1);

namespace OrillaCart\Shipping\Application;

use OrillaCart\Catalog\Application\Dto\QuoteRequest;
use OrillaCart\Catalog\Application\ProductQuotes;
use OrillaCart\Extension\ProviderUnavailable;
use OrillaCart\Extension\Settings;
use OrillaCart\Extension\Shipping\CreatesShipments;
use OrillaCart\Extension\Shipping\Shipment;
use OrillaCart\Extension\Shipping\ShipmentItem;
use OrillaCart\Extension\Shipping\ShipmentRequest;
use OrillaCart\Extension\Shipping\ShippingMethod;
use OrillaCart\Extension\Shipping\Tracking;
use OrillaCart\Extension\Shipping\TracksShipments;
use OrillaCart\Ordering\Application\Dto\AddOrderNote;
use OrillaCart\Ordering\Application\Dto\ChangeOrderStatus;
use OrillaCart\Ordering\Application\Dto\OrderLineView;
use OrillaCart\Ordering\Application\Dto\OrderView;
use OrillaCart\Ordering\Application\OrderService;
use OrillaCart\Ordering\Domain\OrderNotFound;
use OrillaCart\Ordering\Domain\OrderRepository;
use OrillaCart\Payment\Application\PaymentOptions;
use OrillaCart\Settings\Application\SettingsService;
use OrillaCart\Shared\Domain\RuleViolation;
use OrillaCart\Shared\Persistence\Transactions;
use OrillaCart\Shipping\Application\Dto\BookShipment;
use OrillaCart\Shipping\Application\Dto\ConsignmentView;
use OrillaCart\Shipping\Application\Dto\LabelView;
use OrillaCart\Shipping\Application\Dto\OrderShipmentsView;
use OrillaCart\Shipping\Application\Dto\SaveParcel;
use OrillaCart\Shipping\Application\Dto\SendShipment;
use OrillaCart\Shipping\Application\Dto\ShipItem;
use OrillaCart\Shipping\Application\Dto\ShipmentArrived;
use OrillaCart\Shipping\Application\Dto\ShippedItemView;
use OrillaCart\Shipping\Domain\Carrier;
use OrillaCart\Shipping\Domain\CarrierRepository;
use OrillaCart\Shipping\Domain\Consignment;
use OrillaCart\Shipping\Domain\ConsignmentNotFound;
use OrillaCart\Shipping\Domain\ConsignmentRepository;
use OrillaCart\Shipping\Domain\ConsignmentStatus;
use OrillaCart\Shipping\Domain\Event\ConsignmentBooked;
use OrillaCart\Shipping\Domain\Event\OrderReadyForPickup;
use OrillaCart\Shipping\Domain\ParcelTracking;
use OrillaCart\Shipping\Domain\ShippingRuleViolation;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * An order's shipments, from the order screen: booked with the courier of the carrier the customer chose, through
 * its shipping method (the label to print, cancelling one the courier has not picked up, and tracking), or sent by
 * the shop itself with each parcel's tracking number. A shipment carries some or all of the items; the order ships
 * once nothing is left to send, and completes once everything arrived.
 */
final readonly class Consignments
{
    private const string CASH_ON_DELIVERY = 'cash_on_delivery';
    private const array CLOSED = ['cancelled', 'failed', 'refunded'];
    private const int TRACKED_PER_RUN = 1000;

    public function __construct(
        private ConsignmentRepository $consignments,
        private CarrierRepository $carriers,
        private ShippingMethods $methods,
        private OrderService $orders,
        private ProductQuotes $products,
        private SettingsService $settings,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private EventDispatcherInterface $events,
        private PaymentOptions $payments,
        private Transactions $transactions,
        private OrderRepository $orderRecords,
    ) {}

    /**
     * @throws OrderNotFound
     */
    public function forOrder(int $orderId): OrderShipmentsView
    {
        $order = $this->orders->order($orderId);
        $booking = $this->booking($order);
        $consignments = $this->consignments->forOrder($orderId);
        $left = self::left($order, $consignments);
        $pickup = self::isPickup($order);
        $ships = !\in_array($order->status, self::CLOSED, true) && $order->shippingAddress !== null && $left !== [] && !$pickup;

        return new OrderShipmentsView(
            $booking !== null && $ships,
            $booking === null ? null : $booking['carrier']->name(),
            array_map(static fn(Consignment $consignment): ConsignmentView => ConsignmentView::fromConsignment($consignment, self::names($order)), $consignments),
            array_map(static fn(int $line, int $quantity): ShippedItemView => new ShippedItemView($line, self::names($order)[$line] ?? '', $quantity), array_keys($left), $left),
            $ships,
            $pickup ? $order->pickupPoint : null,
            $pickup && \in_array($order->status, ['processing', 'shipped'], true),
        );
    }

    /**
     * An order to collect from one of the shop's places is ready there: the customer hears of it, with the place
     * and when it is open. The shop may say so again.
     *
     * @throws OrderNotFound
     * @throws ShippingRuleViolation for an order not collected from the shop, or not being fulfilled
     */
    public function readyForPickup(int $orderId, ?int $userId): void
    {
        $order = $this->collected($orderId);
        $this->orders->addNote($orderId, new AddOrderNote(\sprintf('Ready to collect at %s.', $order->pickupPoint ?? '')), $userId);
        $this->events->dispatch(new OrderReadyForPickup($orderId));
    }

    /**
     * The customer collected the order: it is completed.
     *
     * @throws OrderNotFound
     * @throws ShippingRuleViolation for an order not collected from the shop, or not being fulfilled
     */
    public function pickedUp(int $orderId, ?int $userId): void
    {
        $order = $this->collected($orderId);
        $this->orders->changeStatus($orderId, new ChangeOrderStatus('completed', note: \sprintf('Collected at %s.', $order->pickupPoint ?? '')), $userId);
    }

    /**
     * Everything arrived: the order is completed, which records its payment. Not so for an order still waiting for
     * a bank transfer or cheque that was sent first: arriving does not pay it, so it waits, shipped, for the shop to
     * mark it paid or complete it.
     */
    private function delivered(OrderView $order, Consignment $consignment): void
    {
        $arrived = \sprintf($consignment->isSentByShop() ? 'Shipment %s arrived.' : 'The courier delivered shipment %s.', $consignment->number());
        if ($order->paidAt !== null || $this->payments->collectsOnDelivery($order->paymentGateway)) {
            $this->orders->changeStatus($order->number, new ChangeOrderStatus('completed', note: $arrived), null);

            return;
        }
        if ($order->status === 'processing') {
            $this->orders->changeStatus($order->number, new ChangeOrderStatus('shipped', note: $arrived), null);
        }
        $this->orders->addNote($order->number, new AddOrderNote($arrived . ' The order is not paid yet, so it stays shipped: mark it paid, or complete it, when the money comes.'), null);
    }

    private function collected(int $orderId): OrderView
    {
        $order = $this->orders->order($orderId);
        if (!self::isPickup($order) || !\in_array($order->status, ['processing', 'shipped'], true)) {
            throw new ShippingRuleViolation('Order #%d is not one to collect from the shop now.', 'orillacart_not_for_pickup', values: [$orderId]);
        }

        return $order;
    }

    private static function isPickup(OrderView $order): bool
    {
        return str_starts_with($order->shippingOption ?? '', 'pickup:');
    }

    /**
     * @throws OrderNotFound
     * @throws ShippingRuleViolation for an order its carrier cannot book, items it does not have left to send, or a courier that refused
     */
    public function book(int $orderId, BookShipment $command, ?int $userId): ConsignmentView
    {
        // Under the order's lock, what is left to send read inside it: a second booking of the same items, sent
        // at once from another tab, waits for this one and is then refused instead of booking them twice.
        return $this->underOrderLock($orderId, fn(): ConsignmentView => $this->booked($orderId, $command, $userId));
    }

    /**
     * @param \Closure(): ConsignmentView $work
     *
     * @param-immediately-invoked-callable $work
     *
     * @throws OrderNotFound
     */
    private function underOrderLock(int $orderId, \Closure $work): ConsignmentView
    {
        return $this->transactions->run(function () use ($orderId, $work): ConsignmentView {
            $this->orderRecords->find($orderId, true) ?? throw new OrderNotFound();

            return $work();
        });
    }

    private function booked(int $orderId, BookShipment $command, ?int $userId): ConsignmentView
    {
        $order = $this->orders->order($orderId);
        $booking = $this->booking($order) ?? throw new ShippingRuleViolation('The order\'s way to ship does not book shipments with a courier.', 'orillacart_shipment_not_bookable');
        $items = $this->sending($order, $command->items);

        $address = $order->shippingAddress ?? $order->billing;
        try {
            $booked = $booking['method']->createShipment(new ShipmentRequest(
                "Order #{$order->number}",
                $booking['service'],
                trim("{$address->firstName} {$address->lastName}"),
                $address->phone,
                $order->email,
                $address->street,
                $address->city,
                $address->postcode,
                $address->country,
                $address->state,
                $order->pickupPointId,
                $this->contents($order, $items),
                $order->paymentGateway === self::CASH_ON_DELIVERY && $order->paidAt === null && !$this->hasShipments($orderId) ? $order->amountDue : null,
                $order->subtotal,
                $command->parcels,
            ), $booking['settings']);
        } catch (ProviderUnavailable $e) {
            throw new ShippingRuleViolation('The courier did not book the shipment: %s', 'orillacart_courier_refused', $e, values: [$e->getMessage()]);
        } catch (\Throwable $e) {
            throw $this->broken('booking', $e);
        }

        $parcels = [];
        foreach ($booked->parcels as $number) {
            try {
                $parcels[] = new ParcelTracking($number);
            } catch (ShippingRuleViolation) {
                $this->logger->warning('{method} gave the parcel number "{number}", which is not one; it is left out.', ['method' => $booking['method']->name(), 'number' => mb_substr($number, 0, 100)]);
            }
        }
        $consignment = Consignment::booked($orderId, $booking['carrier']->id(), $booking['carrier']->name(), $booking['method']->id(), $booked->number, $booked->trackingUrl, $this->clock->now(), $items, $parcels);
        $this->consignments->save($consignment);
        $this->orders->addNote($orderId, new AddOrderNote(\sprintf('Shipment %s booked with %s%s.', $booked->number, $booking['carrier']->name(), self::partly($order, $items))), $userId);
        $this->events->dispatch(new ConsignmentBooked($orderId, $consignment->id()));

        return ConsignmentView::fromConsignment($consignment, self::names($order));
    }

    /**
     * Records a shipment the shop sent itself, as with a courier it has no add-on for: on its way, and the
     * order ships once nothing is left to send.
     *
     * @throws OrderNotFound
     * @throws ShippingRuleViolation for an order that does not ship, or items it does not have left to send
     */
    public function send(int $orderId, SendShipment $command, ?int $userId): ConsignmentView
    {
        return $this->underOrderLock($orderId, fn(): ConsignmentView => $this->sent($orderId, $command, $userId));
    }

    private function sent(int $orderId, SendShipment $command, ?int $userId): ConsignmentView
    {
        $order = $this->orders->order($orderId);
        if ($order->shippingAddress === null) {
            throw new ShippingRuleViolation('Order #%d ships nothing.', 'orillacart_shipment_not_bookable', values: [$orderId]);
        }
        $items = $this->sending($order, $command->items);
        $parcels = array_map(static fn(SaveParcel $parcel): ParcelTracking => new ParcelTracking($parcel->number, $parcel->trackingUrl), $command->parcels);
        $consignment = Consignment::sent($orderId, $command->carrier, $parcels, $this->clock->now(), $items);
        $this->consignments->save($consignment);
        $this->orders->addNote($orderId, new AddOrderNote(\sprintf('Shipment %s sent with %s%s.', $consignment->number(), $consignment->carrier(), self::partly($order, $items))), $userId);
        $this->events->dispatch(new ConsignmentBooked($orderId, $consignment->id()));
        $this->orderFollows($consignment, ConsignmentStatus::OnTheWay);

        return ConsignmentView::fromConsignment($consignment, self::names($order));
    }

    /**
     * The shop says a shipment it sent itself arrived, or came back; the order completes once all it sent arrived.
     *
     * @throws ConsignmentNotFound
     * @throws ShippingRuleViolation for a shipment its courier follows, or one that arrived already
     */
    public function arrived(int $id, ShipmentArrived $command, ?int $userId): ConsignmentView
    {
        $consignment = $this->get($id);
        $status = ConsignmentStatus::from($command->status);
        $consignment->arrived($status, $this->clock->now());
        $this->consignments->save($consignment);
        $this->orders->addNote($consignment->orderId(), new AddOrderNote(\sprintf($status === ConsignmentStatus::Delivered ? 'Shipment %s arrived.' : 'Shipment %s came back.', $consignment->number())), $userId);
        $this->orderFollows($consignment, $status);

        return ConsignmentView::fromConsignment($consignment, self::names($this->orders->order($consignment->orderId())));
    }

    /**
     * @throws ConsignmentNotFound
     * @throws ShippingRuleViolation when the courier cannot give it
     */
    public function label(int $id): LabelView
    {
        $consignment = $this->get($id);
        [$method, $settings] = $this->methodOf($consignment);
        try {
            $label = $method->label($consignment->number(), $settings);
        } catch (ProviderUnavailable $e) {
            throw new ShippingRuleViolation('The courier did not give the label: %s', 'orillacart_courier_refused', $e, values: [$e->getMessage()]);
        } catch (\Throwable $e) {
            throw $this->broken('a label', $e);
        }
        $extension = $label->mimeType === 'application/pdf' ? 'pdf' : (str_starts_with($label->mimeType, 'image/') ? substr($label->mimeType, 6) : 'bin');

        return new LabelView("label-{$consignment->number()}.{$extension}", $label->mimeType, base64_encode($label->content));
    }

    /**
     * @throws ConsignmentNotFound
     * @throws ShippingRuleViolation when the courier has it already, or refuses
     */
    public function cancel(int $id, ?int $userId): ConsignmentView
    {
        $consignment = $this->get($id);
        if ($consignment->status() !== ConsignmentStatus::Booked) {
            throw new ShippingRuleViolation('Shipment %1$s is %2$s; only a booked one is cancelled.', 'orillacart_shipment_not_cancellable', values: [$consignment->number(), str_replace('_', ' ', $consignment->status()->value)]);
        }
        [$method, $settings] = $this->methodOf($consignment);
        try {
            $method->cancelShipment($consignment->number(), $settings);
        } catch (ProviderUnavailable $e) {
            throw new ShippingRuleViolation('The courier did not cancel the shipment: %s', 'orillacart_courier_refused', $e, values: [$e->getMessage()]);
        } catch (\Throwable $e) {
            throw $this->broken('cancelling', $e);
        }
        $consignment->cancel($this->clock->now());
        $this->consignments->save($consignment);
        $this->orders->addNote($consignment->orderId(), new AddOrderNote(\sprintf('Shipment %s cancelled.', $consignment->number())), $userId);

        return ConsignmentView::fromConsignment($consignment);
    }

    /**
     * The shipments on their way, for TrackConsignments to ask their couriers about.
     *
     * @return list<int>
     */
    public function onTheWay(): array
    {
        return array_map(static fn(Consignment $consignment): int => $consignment->id(), $this->consignments->open(self::TRACKED_PER_RUN));
    }

    /**
     * Asks the courier where the shipment is, and the order follows: the first word that it is on its way ships
     * an order being prepared (and tells the customer), delivery completes it, and a return is noted for the shop.
     *
     * @throws \Throwable when the courier cannot answer now: ask again later
     *
     * @return bool whether it changed
     */
    public function track(int $consignmentId): bool
    {
        $consignment = $this->consignments->find($consignmentId);
        if ($consignment === null) {
            return false;
        }
        try {
            [$method, $settings] = $this->methodOf($consignment);
        } catch (ShippingRuleViolation) {
            // Its add-on is gone: nobody to ask.
            return false;
        }
        if (!$method instanceof TracksShipments) {
            return false;
        }
        $tracking = $method->track($consignment->number(), $settings);
        $status = match ($tracking->status) {
            Tracking::BOOKED => ConsignmentStatus::Booked,
            Tracking::DELIVERED => ConsignmentStatus::Delivered,
            Tracking::RETURNED => ConsignmentStatus::Returned,
            default => ConsignmentStatus::OnTheWay,
        };
        if (!$consignment->track($status, $tracking->description, $this->clock->now())) {
            return false;
        }
        $this->consignments->save($consignment);
        $this->orderFollows($consignment, $status);

        return true;
    }

    /**
     * The order follows its shipments: it ships once everything is on its way (a courier's first word, or the
     * shop sending it), completes once everything arrived, and the shop hears of a shipment coming back.
     */
    private function orderFollows(Consignment $consignment, ConsignmentStatus $status): void
    {
        $orderId = $consignment->orderId();
        try {
            $order = $this->orders->order($orderId);
            $consignments = $this->consignments->forOrder($orderId);
            $everything = self::left($order, $consignments) === [];
            $all = static fn(ConsignmentStatus ...$statuses): bool => $everything && array_all(array_filter($consignments, static fn(Consignment $shipment): bool => $shipment->carries()), static fn(Consignment $shipment): bool => \in_array($shipment->status(), $statuses, true));
            match (true) {
                $status === ConsignmentStatus::OnTheWay && $order->status === 'processing' && $all(ConsignmentStatus::OnTheWay, ConsignmentStatus::Delivered) => $this->orders->changeStatus($orderId, new ChangeOrderStatus('shipped', note: \sprintf($consignment->isSentByShop() ? 'Shipment %s sent.' : 'The courier picked up shipment %s.', $consignment->number())), null),
                $status === ConsignmentStatus::Delivered && \in_array($order->status, ['processing', 'shipped'], true) && $all(ConsignmentStatus::Delivered) => $this->delivered($order, $consignment),
                $status === ConsignmentStatus::Returned && !$consignment->isSentByShop() => $this->orders->addNote($orderId, new AddOrderNote(\sprintf('The courier is bringing shipment %s back: %s', $consignment->number(), $consignment->tracking() === '' ? 'no reason given.' : $consignment->tracking())), null),
                default => null,
            };
        } catch (RuleViolation $e) {
            $this->logger->warning('Order {order} did not follow shipment {shipment}: {message}', ['order' => $orderId, 'shipment' => $consignment->number(), 'message' => $e->getMessage()]);
        }
    }

    /**
     * What is left to send of each line that ships: its items, less those refunded and those in a shipment that
     * still carries them.
     *
     * @param list<Consignment> $consignments
     *
     * @return array<int, int> quantities by line position, only those with some left
     */
    private static function left(OrderView $order, array $consignments): array
    {
        $left = [];
        foreach ($order->lines as $position => $line) {
            \assert($line instanceof OrderLineView);
            if ($line->ships) {
                $left[$position] = $line->quantity - $line->refundedQuantity;
            }
        }
        foreach ($consignments as $consignment) {
            if ($consignment->carries()) {
                foreach ($consignment->items() as $position => $quantity) {
                    $left[$position] = ($left[$position] ?? 0) - $quantity;
                }
            }
        }

        return array_filter($left, static fn(int $quantity): bool => $quantity > 0);
    }

    /**
     * The items a new shipment carries: those asked for, or everything left.
     *
     * @param list<ShipItem> $asked
     *
     * @return array<int, int> quantities by line position
     *
     * @throws ShippingRuleViolation for a closed order, or more of a line than is left to send
     */
    private function sending(OrderView $order, array $asked): array
    {
        if (\in_array($order->status, self::CLOSED, true)) {
            throw new ShippingRuleViolation('Order #%1$d is %2$s; it does not ship.', 'orillacart_shipment_not_bookable', values: [$order->number, $order->status]);
        }
        $left = self::left($order, $this->consignments->forOrder($order->number));
        if ($asked === []) {
            return $left !== [] ? $left : throw new ShippingRuleViolation('Everything of order #%d is in a shipment already.', 'orillacart_nothing_to_ship', values: [$order->number]);
        }
        $items = [];
        foreach ($asked as $item) {
            $items[$item->line] = ($items[$item->line] ?? 0) + $item->quantity;
        }
        foreach ($items as $line => $quantity) {
            if ($quantity > ($left[$line] ?? 0)) {
                throw new ShippingRuleViolation('%1$d of "%2$s" are left to send.', 'orillacart_nothing_to_ship', values: [$left[$line] ?? 0, self::names($order)[$line] ?? (string) ($line + 1)]);
            }
        }
        ksort($items);

        return $items;
    }

    /**
     * ", with 2 of the 5 items", for a shipment that leaves some behind.
     *
     * @param array<int, int> $items quantities by line position
     */
    private static function partly(OrderView $order, array $items): string
    {
        $all = array_sum(array_map(static fn(OrderLineView $line): int => $line->ships ? $line->quantity - $line->refundedQuantity : 0, $order->lines));
        $sent = array_sum($items);

        return $sent < $all ? \sprintf(', with %d of the %d items', $sent, $all) : '';
    }

    /** @return array<int, string> the order's line names, by position */
    private static function names(OrderView $order): array
    {
        return array_map(static fn(OrderLineView $line): string => $line->name, $order->lines);
    }

    private function hasShipments(int $orderId): bool
    {
        return array_filter($this->consignments->forOrder($orderId), static fn(Consignment $consignment): bool => $consignment->carries()) !== [];
    }

    /**
     * The carrier, its method and the service the customer chose, when the method books shipments.
     *
     * @return array{carrier: Carrier, method: ShippingMethod&CreatesShipments, service: string, settings: Settings}|null
     */
    private function booking(OrderView $order): ?array
    {
        if ($order->shippingOption === null || preg_match('/^carrier:(\d+):([a-z0-9_]{1,40})$/', $order->shippingOption, $parts) !== 1) {
            return null;
        }
        $carrier = $this->carriers->find((int) $parts[1]);
        $method = $carrier?->method() === null ? null : $this->methods->find($carrier->method());
        if ($carrier === null || !$method instanceof CreatesShipments) {
            return null;
        }

        return ['carrier' => $carrier, 'method' => $method, 'service' => $parts[2], 'settings' => new Settings($carrier->methodSettings())];
    }

    /**
     * The shipping method of an add-on broke: logged, and told to the shop as the courier failing.
     */
    private function broken(string $doing, \Throwable $e): ShippingRuleViolation
    {
        $this->logger->error('The shipping method failed {doing}: {message}', ['doing' => $doing, 'message' => $e->getMessage(), 'exception' => $e]);

        return new ShippingRuleViolation('The shipping method failed: %s', 'orillacart_courier_failed', $e, values: [$e->getMessage()]);
    }

    /**
     * @return array{ShippingMethod&CreatesShipments, Settings}
     *
     * @throws ShippingRuleViolation when the carrier or its method is gone
     */
    private function methodOf(Consignment $consignment): array
    {
        $carrier = $consignment->carrierId() === null ? null : $this->carriers->find($consignment->carrierId());
        $method = $consignment->method() === null ? null : $this->methods->find($consignment->method());
        if (!$carrier instanceof Carrier || !$method instanceof CreatesShipments) {
            throw new ShippingRuleViolation($consignment->isSentByShop() ? 'Shipment %s was sent by the shop; it has no courier to ask.' : 'The shipping method that booked shipment %s is not active.', 'orillacart_unknown_shipping_method', values: [$consignment->number()]);
        }

        return [$method, new Settings($carrier->methodSettings())];
    }

    /**
     * What the shipment carries, measured as the catalog measures it now.
     */
    /**
     * @param array<int, int> $sending quantities by line position
     */
    private function contents(OrderView $order, array $sending): Shipment
    {
        $shop = $this->settings->current();
        $address = $order->shippingAddress ?? $order->billing;
        $items = [];
        foreach ($order->lines as $position => $line) {
            \assert($line instanceof OrderLineView);
            if (!isset($sending[$position])) {
                continue;
            }
            $line = new OrderLineView($line->productId, $line->variationId, $line->name, $line->sku, $line->options, $sending[$position], $line->unitPrice, $line->total, $line->tax, $line->optionIds, bundleChoices: $line->bundleChoices);
            try {
                $quote = $this->products->quote(new QuoteRequest($line->productId, $line->optionIds, bundleChoices: $line->bundleChoices));
            } catch (RuleViolation) {
                $items[] = new ShipmentItem($line->quantity, name: $line->name);
                continue;
            }
            if ($quote->type === 'physical') {
                $items[] = new ShipmentItem($line->quantity, $quote->weight, $quote->length, $quote->width, $quote->height, $line->name);
            }
        }

        return new Shipment($address->country, $address->state, $address->postcode, $items, $order->subtotal, $order->currency, $shop->weightUnit, $shop->dimensionUnit);
    }

    private function get(int $id): Consignment
    {
        return $this->consignments->find($id) ?? throw new ConsignmentNotFound($id);
    }
}
